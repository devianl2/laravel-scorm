# Laravel Scorm Handler
## _Design for Laravel LMS_

[![N|Solid](https://peopleaps.com/wp-content/uploads/2020/11/p2-01-01.png)](https://www.peopleaps.com)


Laravel Scorm Handler is a laravel package that simplify scorm package contents (zip file) into laravel storage.

Highlight of this package:
- Zipfile handler with auto extract and store sco into database
- Store user CMI data into database
- Get user last learning data

## _Things you must know before you install:_
1) You have a domain/subdomain to serve scorm content
2) Scorm content folder/path must be outside from laravel application (Security issue).
3) Virtual host to point domain/subdomain to scorm content directory (E.g: /scorm/hashed_folder_name/)
4) Uploaded file should have the right permission to extract scorm files into scorm content directory
5) This package will handle folder creation into scorm content directory (E.g: /scorm/{auto_generated_hashname}/imsmanifest.xml)


## Step 1:
Install from composer (For flysystem v1)
```sh
composer require devianl2/laravel-scorm:"^3.0"
```

Install from composer (For flysystem v2/v3)
```sh
composer require devianl2/laravel-scorm
```

## Step 2:
Run vendor publish for migration and config file
```sh
php artisan vendor:publish --provider="Peopleaps\Scorm\ScormServiceProvider"
```

## Step 3:
Run config cache for update cached configuration
```sh
php artisan config:cache
```

## Step 4:
Migrate file to database
```sh
php artisan migrate
```

## Step 5 (Optional):
***Update SCORM config under `config/scorm`***
- update scorm table names.
- update SCORM disk and configure disk @see config/filesystems.php
```
    'disk'  =>  'scorm-local',
    'disk'  =>  'scorm-s3',

 // @see config/filesystems.php
     'disks' => [
         .....
         'scorm-local' => [
            'driver'     => 'local',
            'root'       =>  env('SCORM_ROOT_DIR'), // set root dir
            'visibility' => 'public',
        ],

        's3-scorm' => [
            'driver' => 's3',
            'root'   => env('SCORM_ROOT_DIR'), // set root dir
            'key'    => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_SCORM_BUCKET'),
        ],
        .....
     ]
```
***Update SCORM translations under `resources/lang/en-US/scorm.php`***
- SCORM runtime errors exceptions handler, *(Check next example)*
- Copy and translate error msg with key for other locale as you wish.
  
*After finishing don't forget to run `php artisan config:cache`*

  
## Step 6 (Optional):

**Usage**
```
class ScormController extends BaseController
{
    /** @var ScormManager $scormManager */
    private $scormManager;
    /**
     * ScormController constructor.
     * @param ScormManager $scormManager
     */
    public function __construct(ScormManager $scormManager)
    {
        $this->scormManager = $scormManager;
    }

    public function show($id)
    {
        $item = ScormModel::with('scos')->findOrFail($id);
        // response helper function from base controller reponse json.
        return $this->respond($item);
    }

    public function store(ScormRequest $request)
    {
        try {
            $scorm = $this->scormManager->uploadScormArchive($request->file('file'));
            // handle scorm runtime error msg
        } catch (InvalidScormArchiveException | StorageNotFoundException $ex) {
            return $this->respondCouldNotCreateResource(trans('scorm.' .  $ex->getMessage()));
        }

        // response helper function from base controller reponse json.
        return $this->respond(ScormModel::with('scos')->whereUuid($scorm['uuid'])->first());
    }

    public function saveProgress(Request $request)
    {
        // TODO save user progress...
    }
}
```

***Upgrade from version 2 to 3:***
Update your Scorm table:
- Add entry_url (varchar 191 / nullable)
- Change hash_name to title
- Remove origin_file_mime field

***Upgrade from version 3 to 4:***
Update your Scorm table:
- Add identifier (varchar 191)

