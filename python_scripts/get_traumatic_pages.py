#!/usr/bin/env python

import sys
import MySQLdb
import gc
from toolserver import ToolserverConfig
import csv
import simplejson
import urllib


def main():

    import optparse
    p = optparse.OptionParser(
        usage="usage: %prog [options] input_file output_file")
    p.add_option('-t', '--traumatic', action="store", dest="traumatic",
                 help="Traumatic categories list (semicolon separated)")
    p.add_option('-H', '--human', action="store", dest="human",
                 help="Human disasters categories list (semicolon separated)")
    p.add_option('-n', '--natural', action="store", dest="natural",
                 help=("Natural disasters categories list"
                       "(semicolon separated)"))
    p.add_option('-N', '--non-traumatic', action="store", dest="non_traumatic",
                 help="Non traumatic categories list (semicolon separated)")
    p.add_option('-e', '--min-edits', action="store", dest="min_edits",
                 help="Minimum number of edits")
    opts, files = p.parse_args()

    if len(files) != 2:
        p.error("Wrong parameters")

    traumatic = opts.traumatic.split(";")
    human = opts.human.split(";")
    natural = opts.natural.split(";")
    non_traumatic = opts.non_traumatic.split(";")

    lang = "en"
    family = "wikipedia"
    server = ""
    dbname = ""

    subcats = []
    for line in open(files[0]):
        subcat = line[:-1]
        subcats.append(
            (
                subcat,
                subcat in traumatic,
                subcat in non_traumatic,
                subcat in natural,
                subcat in human
            )
        )
    subcats = tuple(subcats)

    # connect to the MySQL server
    tsc = ToolserverConfig()

    try:
        conn = MySQLdb.connect(host=tsc.host,
                               user=tsc.user,
                               passwd=tsc.password,
                               db="toolserver")
    except MySQLdb.Error, e:
        print "Error %d: %s" % (e.args[0], e.args[1])
        sys.exit(1)

    cursor = conn.cursor()
    cursor.execute("""SELECT server, dbname FROM wiki
                      WHERE lang=%s AND family=%s""",
                   (lang, family))
    row = cursor.fetchone()
    if row == None:
        print "Invalid wiki name!"
        sys.exit(1)
    server = "sql-s%d" % row[0]
    dbname = row[1]
    conn.close()

    print "Now connecting to ", server, dbname

    try:
        conn = MySQLdb.connect(host=server,
                               user=tsc.user,
                               passwd=tsc.password,
                               db=dbname)
    except MySQLdb.Error, e:
        print "Error %d: %s" % (e.args[0], e.args[1])
        sys.exit(1)

    counter = 0
    cursor = conn.cursor()
    while len(subcats) > 0:
        counter += 1
        print "fetching subcats level", counter

        new_subcats = []

        fs = [open("%s_%d" % (files[1], counter), "w"),
              open("%s_%d_talks" % (files[1], counter), "w")]
        csv_writer = [csv.writer(x) for x in fs]

        for subcat, is_t, is_nt, is_n, is_h in subcats:
            query = """
                        SELECT DISTINCT page_title, page_id, cl_type
                        FROM page, categorylinks
                        WHERE cl_from = page_id
                        AND cl_to = '%s'
                """ % (subcat,)
            #print query
            cursor.execute(query)
            result_set = cursor.fetchall()
            #print result_set
            print len(result_set)

            for row in result_set:
                if row[2] == "subcat":
                    new_subcats.append(
                        (
                            subcat,
                            is_t or subcat in traumatic,
                            is_nt or subcat in non_traumatic,
                            is_n or subcat in natural,
                            is_h or subcat in human
                        )
                    )
                elif row[2] == "page":
                    page, page_id = row[0], row[1]
                    for page, t in [(page, 1), ("Talk:%s" % page, 0)]:
                        if t == 0:
                            url = ("http://%s.%s.org/w/api.php?action=query&"
                                   "titles=%s&format=json") % \
                                   (lang, family, page)
                            data = simplejson.load(urllib.urlopen(url))
                            page_id = data["query"]["pages"].keys()[0]

                        if page_id != -1:
                            query = """
                                SELECT COUNT(*) AS tot_edits,
                                       COUNT(DISTINCT rev_user) AS editors
                                FROM revision, user, user_groups
                                WHERE rev_page=%s AND
                                      rev_user=user_id AND
                                      user_id=ug_user AND
                                      ug_group!="bot";""" % page_id
                            cursor.execute(query)
                            row = list(cursor.fetchone())

                            if row[0] > opts.min_edits and t == 1:
                                belonging = [is_t, is_nt, is_n, is_h]
                                csv_writer[t].writerow(
                                    [page, t] + row + belonging
                                )
                            else:
                                continue
            del result_set
            gc.collect()
        [x.close() for x in fs]

        del subcats
        subcats = None
        gc.collect()
        subcats = tuple(new_subcats)

    conn.commit()
    conn.close()

if __name__ == "__main__":
    main()
