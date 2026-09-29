<?php /*

[CronjobSettings]
ExtensionDirectories[]=ezoracle

# The Oracle maintenance jobs. Each script does nothing until it is enabled in
# ezoracle.ini [CronjobSettings] (GatherStatistics, HealthMonitor,
# PurgeRecycleBin, SequenceCheck), and exits at once on a database that is not
# Oracle. Run them, for example nightly and hourly:
#   php runcronjobs.php -s <admin siteaccess> ezoracle          (nightly)
#   php runcronjobs.php -s <admin siteaccess> ezoraclehealth    (hourly)
# or one by one: php runcronjobs.php ... ezoracle_statistics

[CronjobPart-ezoracle]
Scripts[]
Scripts[]=ezoracle_statistics.php
Scripts[]=ezoracle_recyclebin.php
Scripts[]=ezoracle_sequences.php
Scripts[]=ezoracle_health.php

[CronjobPart-ezoraclehealth]
Scripts[]
Scripts[]=ezoracle_health.php

[CronjobPart-ezoracle_statistics]
Scripts[]
Scripts[]=ezoracle_statistics.php

[CronjobPart-ezoracle_recyclebin]
Scripts[]
Scripts[]=ezoracle_recyclebin.php

[CronjobPart-ezoracle_sequences]
Scripts[]
Scripts[]=ezoracle_sequences.php

*/ ?>
