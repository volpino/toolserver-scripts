#!/usr/bin/env python

import sys
import MySQLdb
import csv
from toolserver import ToolserverConfig

LANG = "en"
FAMILY = "wikipedia"

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
cursor.execute ("SELECT server, dbname, lang FROM wiki WHERE family=%s",
                (FAMILY, ))
rows = cursor.fetchall()

if rows is None:
    print "Couldn't find any wiki! :("
    sys.exit(1)

toolserver_data = [("sql-s%d" % row[0], row[1], row[2]) for row in rows]

conn.close()

prev_server = ""
conn = None
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
    query = """
            SELECT user_id, user_name, up_value
            FROM user
                LEFT JOIN user_properties
                ON up_user=user_id
            WHERE up_property='gender';
        """
    try:
        cursor.execute(query)
        result_set = cursor.fetchall()
        if result_set:
            with open("%s_users_gender.csv" % dbname, "w") as f:
                csv_writer = csv.writer(f)
                csv_writer.writerows(result_set)
        conn.commit ()
    except:
        print "ERROR while doing the query :(, skipping"

conn.close ()
