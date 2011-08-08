We developed some simple APIs to retrieve data from the Toolserver database. They are used on Manypedia and WikiTrip

*   [api.php][1]

    Get various stats about a page

    Options:

    *   article: page title
    *   lang: desidered language (default: en)
    *   family: wiki family (default: wikipedia)
    *   year_count: show edit count per month (default: false)
    *   editors: show unique editors for the page (default: false)
    *   max_editors: maximum number of editors displayed (only if "editors" option is set)
    *   anons: show anonymous unique editors (default: false)
    *   top_ten: show top 10% of editors (default: false)
    *   top_fifty: top 50 editors (default: false)

    [Example][2]



*   [api_gender.php][3]
    Get timestamp and gender for any edit by a registered user that specified his gender on a specific page (might be quite slow)

    Options:

    *   article: page title
    *   lang: desidered language (default: en)
    *   family: wiki family (default: wikipedia)

    [Example][4]



*   [api_geojson.php][5]
    Get a GeoJSON for anonymous edits on a specific page

    Options:

    *   article: page title
    *   lang: desidered language (default: en)
    *   family: wiki family (default: wikipedia)

    [Example][6]



Note: The API's output is in JSON format



The APIs are open source and much code has been taken from [Xi!'s articleinfo][7]. We thank Xi a lot for his awesome work!

 [1]: http://toolserver.org/~sonet/api.php
 [2]: http://toolserver.org/~sonet/api.php?article=London&lang=en&editors&max_editors=5
 [3]: http://toolserver.org/~sonet/api_gender.php
 [4]: http://toolserver.org/~sonet/api_gender.php?article=London&lang=en
 [5]: http://toolserver.org/~sonet/api_geojson.php
 [6]: http://toolserver.org/~sonet/api_geojson.php?article=London&lang=en
 [7]: http://toolserver.org/~soxred93/articleinfo/
