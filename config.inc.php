<?php

// backend type (database, kolab, caldav)
$config['tasklist_driver'] = 'caldav';

// CalDAV server location (required when tasklist_driver = caldav)
$config['tasklist_caldav_server'] = "https://genesworld.net/cloud/remote.php/dav/calendars/%u/%i/";

// default sorting order of tasks listing (auto, datetime, startdatetime, flagged, complete, changed)
$config['tasklist_sort_col'] = '';

// default sorting order for tasks listing (asc or desc)
$config['tasklist_sort_order'] = 'asc';

