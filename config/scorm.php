<?php

return [

    'table_names' =>  [
        'user_table'   =>  'users',
        'scorm_table'   =>  'scorm',
        'scorm_sco_table'   =>  'scorm_sco',
        'scorm_sco_tracking_table'   =>  'scorm_sco_tracking',
    ],
    // Scorm directory. You may create a custom path in file system
    'disk'  =>  'local',
    // Path to generated scorm folder. (Eg: path/scorm-folder/{generated hashname folder}/{scomfiles}
    'upload_path'   =>  'scorm-folder/',

];
