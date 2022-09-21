<?php

return [

    'table_names' =>  [
        'user_table'                    =>  'users', // user table name on main LMS app.
        'resource_table'                =>  'resource', // resource table on LMS app.
        'scorm_table'                   =>  'scorm',
        'scorm_sco_table'               =>  'scorm_sco',
        'scorm_sco_tracking_table'      =>  'scorm_sco_tracking',
    ],
    /**
     * Scorm directory. You may create a custom path in file system
     * Define Scorm disk under @see config/filesystems.php
     * 'disk'  =>  'local',
     * 'disk'  =>  's3-scorm',
     * ex.
     * 's3-scorm' => [
     * 'driver' => 's3',
     * 'root'   => env('SCORM_ROOT_DIR'),  // define root dir
     * 'key'    => env('AWS_ACCESS_KEY_ID'),
     * 'secret' => env('AWS_SECRET_ACCESS_KEY'),
     * 'region' => env('AWS_DEFAULT_REGION'),
     * 'bucket' => env('AWS_SCORM_BUCKET'),
     * ],
     */
    'disk'       =>  'local',
    'archive'    => 'local',
];
