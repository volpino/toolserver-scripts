#!/usr/bin/env python

import sys
import MySQLdb
import csv
from toolserver import ToolserverConfig

def perc(val, total):
    try:
        return float(val) / float(total)
    except ZeroDivisionError:
        return 0

def get_data(start_date=None, output=sys.stdout, family="wikipedia"):
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
    cursor.execute ("SELECT server, dbname, lang FROM wiki WHERE family=%s",
                    (family, ))
    rows = cursor.fetchall()

    if rows is None:
        print "Couldn't find any wiki! :("
        sys.exit(1)

    toolserver_data = [("sql-s%d" % row[0], row[1], row[2]) for row in rows]

    conn.close()

    prev_server = ""
    conn = None
    user_count = "SELECT COUNT(*) FROM user"
    user_count_reg = "SELECT COUNT(*) FROM user WHERE user_registration > %s"
    gender_count = """ SELECT up_value, COUNT(*)
                       FROM user_properties
                       WHERE up_property='gender'
                       GROUP BY up_value; """
    gender_count_reg = """ SELECT up_value, COUNT(*)
                           FROM user_properties JOIN user ON up_user=user_id
                           WHERE up_property='gender' AND
                                 user_registration > %s
                           GROUP BY up_value; """
    f = open(output, 'w')
    fields =  ["lang", "total", "gender", "gender_rel", "nogender",
               "nogender_rel", "female", "female_rel", "male", "male_rel"]
    csv_writer = csv.DictWriter(f, fields)
    csv_writer.writeheader()
    for data in toolserver_data:
        server, dbname, lang = data
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
        res = {"lang": lang,
               "male": 0,
               "female": 0}
        if start_date:
            cursor.execute(user_count_reg, (start_date, ))
        else:
            cursor.execute(user_count, start_date)
        result_set = cursor.fetchone()
        res["total"] = result_set[0]
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
        res["male_rel"] = perc(res["male"], res["total"])
        res["female_rel"] = perc(res["female"], res["total"])
        res["gender"] = res["male"] + res["female"]
        res["gender_rel"] = perc(res["gender"], res["total"])
        res["nogender"] = res["total"] - res["gender"]
        res["nogender_rel"] = perc(res["nogender"], res["total"])
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
