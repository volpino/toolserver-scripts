#!/usr/bin/env python

import sys
import MySQLdb
import csv
import simplejson
import urllib
from toolserver import ToolserverConfig

if len(sys.argv) != 3:
    print "Usage: %s pages_list output_file" % sys.argv[0]
    print "Error: Wrong parameters!"
    sys.exit(0)

LANG = "en"
FAMILY = "wikipedia"
SERVER = ""
DBNAME = ""

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
cursor.execute ("SELECT server, dbname FROM wiki WHERE lang=%s AND family=%s",
                (LANG, FAMILY))
row = cursor.fetchone()
if row == None:
    print "Invalid wiki name!"
    sys.exit(1)
SERVER = "sql-s%d" % row[0]
DBNAME = row[1]
conn.close()

print "Now connecting to ", SERVER, DBNAME

try:
    conn = MySQLdb.connect (host=SERVER,
                            user=tsc.user,
                            passwd=tsc.password,
                            db = DBNAME)
except MySQLdb.Error, e:
    print "Error %d: %s" % (e.args[0], e.args[1])
    sys.exit (1)

infile = open(sys.argv[1])
csv_writer = csv.writer(open(sys.argv[2], 'w'), delimiter='|')
csv_writer.writerow(["page", "type", "total_edits", "unique_editors"])
cursor = conn.cursor()
queue = []
counter = 0
for page in infile:
    page = page[:-1]
    if not page:
        continue
    for page, t in [(page, 1), ("Talk:%s" % page, 0)]:
        #print page
        url = "http://%s.%s.org/w/api.php?action=query&titles=%s&format=json" \
              % (LANG, FAMILY, page)
        data = simplejson.load(urllib.urlopen(url))
        page_id = data["query"]["pages"].keys()[0]
        if page_id != -1:
            query = """SELECT COUNT(*) AS total_edits,
                              COUNT(DISTINCT rev_user) AS unique_editors
                       FROM revision, user, user_groups
                       WHERE rev_page=%s AND
                             rev_user=user_id AND
                             user_id=ug_user AND
                             ug_group!="bot";""" % page_id
            cursor.execute(query)
            row = cursor.fetchone()
            if row:
                counter += 1
                if counter % 100 == 0:
                    print "flushing", counter
                    csv_writer.writerows(queue)
                    queue = []
                queue.append([page, t] + list(row))
            else:
                print "Error: page %s" % page
csv_writer.writerows(queue)
conn.commit ()
conn.close ()
