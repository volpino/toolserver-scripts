#!/usr/bin/env python

import sys
import MySQLdb
import csv
from toolserver import ToolserverConfig
import urllib
import simplejson
import pycountry
import re

FIND_LINK = re.compile(r"\[\[[^\[\]]+\]\]")


def perc(val, total):
    try:
        return float(val) / float(total)
    except (ZeroDivisionError, TypeError, ValueError):
        return 0


def get_translation(code):
    name = ""
    try:
        name = pycountry.languages.get(alpha2=code.lower()).name
    except KeyError:
        pass
    try:
        name = pycountry.languages.get(bibliographic=code.lower()).name
    except KeyError:
        pass
    try:
        name = pycountry.countries.get(alpha2=code.upper()).name
    except KeyError:
        pass
    return name.encode("utf-8")


def get_welcome_links(lang="en"):
    api_base = "http://en.wikipedia.org/w/api.php"
    article = "Mediawiki:Welcomecreation"
    options = {"action": "query",
               "titles": article,
               "redirects": 1,
               "prop": "revisions",
               "rvprop": "content",
               "format": "json"}
    url = "%s?%s" % (api_base, urllib.urlencode(options))
    result = simplejson.load(urllib.urlopen(url))
    page_id = result["query"]["pages"].keys()[0]
    links = -1
    try:
        content = result["query"]["pages"][page_id]["revisions"][0]["*"]
    except KeyError:
        pass
    else:
        links = len(FIND_LINK.findall(content))
    return links


def get_welcome_data(lang="en", family="wikipedia"):
    api_base = "http://toolserver.org/~sonet/api.php"
    article = "Mediawiki:Welcomecreation"
    options = {"article": article,
               "lang": lang,
               "family": family}
    url = "%s?%s" % (api_base, urllib.urlencode(options))
    try:
        result = simplejson.load(urllib.urlopen(url))
    except:
        return -1, -1
    edits = -1
    editors = -1
    if not "error" in result:
        edits = result["count"]
        editors = result["editor_count"]
    return edits, editors


def get_data(start_date=None, output=sys.stdout, family="wikipedia"):
    """
    Gets the data from the database and writes a CSV output file with
    the number of people who specified their gender, number of males and
    females and other correlate data.
    """

    # add hours, min and seconds to start date in order to compare it
    # with user_registration
    if start_date:
        start_date = "%s000000" % start_date

    tsc = ToolserverConfig()
    # connect to the MySQL server
    try:
        conn = MySQLdb.connect (host=tsc.host,
                                user=tsc.user,
                                passwd=tsc.password,
                                db="toolserver")
    except MySQLdb.Error, e:
        print "Error %d: %s" % (e.args[0], e.args[1])
        sys.exit (1)

    cursor = conn.cursor ()
    cursor.execute ("""SELECT server, dbname, lang, domain
                       FROM wiki WHERE family=%s""",
                    (family, ))
    rows = cursor.fetchall()

    if rows is None:
        print "Couldn't find any wiki! :("
        sys.exit(1)

    toolserver_data = [("sql-s%d" % row[0], row[1], row[2], row[3])
                       for row in rows]

    conn.close()

    prev_server = ""
    conn = None
    user_count = "SELECT /* SLOW_OK */ COUNT(*) FROM user"
    edit_count = "SELECT /*SLOW_OK */ SUM(user_editcount) FROM user"
    user_count_reg = ("SELECT /* SLOW_OK */ COUNT(*) FROM user "
                      "WHERE user_registration > %s")
    gender_count = """ SELECT /* SLOW_OK */
                           up_value, COUNT(*), SUM(user_editcount)
                       FROM user_properties JOIN user ON up_user=user_id
                       WHERE up_property='gender'
                       GROUP BY up_value; """
    gender_count_reg = """ SELECT /* SLOW_OK */
                               up_value, COUNT(*), SUM(user_editcount)
                           FROM user_properties JOIN user ON up_user=user_id
                           WHERE up_property='gender' AND
                                 user_registration > %s
                           GROUP BY up_value; """
    f = open(output, 'w')
    fields =  ["lang", "hlang", "domain", "total", "gender", "gender_rel",
               "nogender", "nogender_rel", "female", "female_rel", "male",
               "male_rel", "total_edits", "gender_edits", "gender_rel_edits",
               "nogender_edits", "nogender_rel_edits", "female_edits",
               "female_rel_edits", "male_edits", "male_rel_edits",
               "welcome_edits", "welcome_editors", "welcome_links"]
    csv_writer = csv.DictWriter(f, fields)
    csv_writer.writeheader()
    for data in toolserver_data:
        server, dbname, lang, domain = data
        print "Now processing ", server, dbname
        # if the database to process is on the same server don't disconnect,
        # just change db ;)
        if prev_server != server or conn is None:
            try:
                if conn:
                    conn.close()
                print "New connection to", server
                conn = MySQLdb.connect (host=server,
                                        user=tsc.user,
                                        passwd=tsc.password,
                                        db=dbname)
                prev_server = server
            except MySQLdb.Error, e:
                print "Error %d: %s" % (e.args[0], e.args[1])
                sys.exit (1)
        else:
            print "Changing db to", dbname
            conn.select_db(dbname)

        cursor = conn.cursor()
        edits, editors = get_welcome_data(lang)
        res = {"lang": lang,
               "domain": domain,
               "hlang": get_translation(lang),
               "welcome_edits": edits,
               "welcome_editors": editors,
               "welcome_links": get_welcome_links(lang),
               "male": 0,
               "female": 0,
               "male_edits": 0,
               "female_edits": 0}

        result_set = None
        counter = 0
        while result_set is None and counter <= 2:
            if start_date:
                cursor.execute(user_count_reg, (start_date, ))
            else:
                cursor.execute(user_count, start_date)
            result_set = cursor.fetchone()
            counter += 1
        if not result_set:
            print "Query killed on %s, skipping!" % dbname
            continue
        res["total"] = result_set[0]
        counter = 0
        while result_set is None and counter <= 2:
            cursor.execute(edit_count)
            result_set = cursor.fetchone()
        if not result_set:
            print "Query killed on %s, skipping!" % dbname
            continue
        res["total_edits"] = result_set[0]
        if not res["total_edits"]:
            continue
        try:
            if start_date:
                cursor.execute(gender_count_reg, (start_date, ))
            else:
                cursor.execute(gender_count)
            result_set = cursor.fetchall()
        except:
            print "Error while retrieving data from %s, skipping!" % dbname
            continue

        for row in result_set:
            res[row[0]] = row[1]
            res["%s_edits" % row[0]] = row[2]
        res["gender"] = res["male"] + res["female"]
        res["male_rel"] = perc(res["male"], res["gender"])
        res["female_rel"] = perc(res["female"], res["gender"])
        res["gender_rel"] = perc(res["gender"], res["total"])
        res["nogender"] = res["total"] - res["gender"]
        res["nogender_rel"] = perc(res["nogender"], res["total"])
        res["gender_edits"] = res["male_edits"] + res["female_edits"]
        res["female_rel_edits"] = perc(res["female_edits"],
                                       res["gender_edits"])
        res["male_rel_edits"] = perc(res["male_edits"], res["gender_edits"])
        res["gender_rel_edits"] = perc(res["gender_edits"], res["total_edits"])
        res["nogender_edits"] = res["total_edits"] - res["gender_edits"]
        res["nogender_rel_edits"] = perc(res["nogender_edits"],
                                         res["total_edits"])
        csv_writer.writerow(res)
        conn.commit()

    f.close()
    conn.close ()


def main():
    import optparse
    p = optparse.OptionParser(
        usage="usage: %prog output_file")
    p.add_option('-s', '--start', action="store", dest="start_date",
                 default=None, help="Select users registered from this date"
                                    " (format: yyyymmdd)")
    opts, files = p.parse_args()
    if len(files) != 1:
        p.error("Wrong parameters")
    get_data(opts.start_date, files[0])

if __name__ == "__main__":
    main()
