import csv
from sys import argv
from collections import defaultdict


def main():
    data = defaultdict(lambda: [0, 0, 0, 0, 0, 0])
    csv_writer = csv.writer(open(argv[1], "w"))
    csv_reader = None
    for arg in argv[2:]:
        csv_reader = csv.reader(open(arg))
        for line in csv_reader:
            data[line[0]] = [int(data[line[0]][i] or e)
                             for i, e in enumerate(line[1:])]
    for key in data:
        csv_writer.writerow([key] + data[key])


if __name__ == "__main__":
    main()
