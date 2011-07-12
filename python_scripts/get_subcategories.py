#!/usr/bin/env python

import sys
import MySQLdb
import gc
from toolserver import ToolserverConfig

def main():
    if len(sys.argv) != 2:
        print "Usage: %s input_file" % sys.argv[0]
        print "Error: Wrong parameters!"
        sys.exit(0)

    lang = "en"
    family = "wikipedia"
    server = ""
    dbname = ""

    subcats = tuple([line[:-1] for line in open(sys.argv[1])])
    pages = []

    # connect to the MySQL server
    tsc = ToolserverConfig()

    try:
        conn = MySQLdb.connect (host=tsc.host,
                                user=tsc.user,
                                passwd=tsc.password,
                                db="toolserver")
    except MySQLdb.Error, e:
        print "Error %d: %s" % (e.args[0], e.args[1])
        sys.exit (1)

    cursor = conn.cursor ()
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
        conn = MySQLdb.connect (host=server,
                                user=tsc.user,
                                passwd=tsc.password,
                                db=dbname)
    except MySQLdb.Error, e:
        print "Error %d: %s" % (e.args[0], e.args[1])
        sys.exit (1)


    counter = 0
    cursor = conn.cursor()
    while len(subcats) > 0:
        counter += 1
        print "fetching subcats level", counter
        # get subcategories and loop
        query = """
                    SELECT page_title, cl_type
                    FROM page, categorylinks
                    WHERE cl_from = page_id
                    AND cl_to IN %s
            """ % (subcats,)
        #print query
        cursor.execute(query)
        result_set = cursor.fetchall ()
        #print result_set
        print len(result_set)
        del subcats
        subcats = None
        gc.collect()
        subcats = tuple([row[0] for row in result_set if row[1] == "subcat"])
        pages = [row[0] for row in result_set if row[1] == "page"]
        f = open("%d.txt" % counter, "w")
        for page in pages:
            f.write(page+"\n")
        f.close()
        del pages
        del result_set
        pages = None
        result_set = None
        gc.collect()

    conn.commit ()
    conn.close ()

if __name__ == "__main__":
    main()
