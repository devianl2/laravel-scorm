# Laravel Scorm Handler (DISCONTINUE)
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
            // Simple upload - all metadata auto-detected from manifest
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

***Upgrade to latest version:***
Update your Scorm table with metadata:
- Add metadata (json, nullable)

Run the migration:
```sh
php artisan migrate
```

## **New Features in Latest Version:**

### **🎯 Automatic Metadata Extraction**
The package now automatically extracts metadata from SCORM manifest files:
- **Creation Date**: Extracted from manifest metadata
- **Creator/Author**: Extracted from manifest metadata  
- **Package Size**: Auto-detected from uploaded file
- **No Manual Input Required**: Everything is detected automatically

### **📦 Enhanced SCORM Support**
- **Adobe Captivate Support**: Handles packages with nested `.cpt` folders
- **Flexible Manifest Location**: Supports `imsmanifest.xml` in root or subdirectories
- **Robust Validation**: Better error handling and ZIP file validation
- **Resource Management**: Proper cleanup of streams and ZIP archives

### **🔧 Simplified API**
```php
// Simple upload - all metadata auto-detected
$scorm = $scormManager->uploadScormArchive($file);

// Upload from URI
$scorm = $scormManager->uploadScormFromUri($fileUrl);

// Update existing SCORM (provide UUID)
$scorm = $scormManager->uploadScormArchive($file, $existingUuid);
```

### **⚙️ Choosing unzipper strategy**
```php
use Peopleaps\Scorm\Manager\ScormManager;
use Peopleaps\Scorm\Contract\UnzipperInterface;
use Peopleaps\Scorm\Manager\LambdaUnzipper;

// 1. Laravel container (default — LocalUnzipper, no extra config needed)
$manager = app(ScormManager::class);

// 2. Swap to LambdaUnzipper via the container (e.g. in AppServiceProvider::register)
$this->app->bind(UnzipperInterface::class, function () {
    return new LambdaUnzipper(/* inject your client, bucket, etc. */);
});

// 3. Manual instantiation (e.g. in tests or outside Laravel)
$manager = new ScormManager(
    // ScormDisk is resolved for you when using the container;
    // here you can wire a custom unzipper manually if needed.
);
```

### **📊 Metadata Structure**
The system automatically captures:
```json
{
    "package_size": 1048576,
    "created_at": "2024-01-15T10:30:00.000Z",
    "created_by": "John Doe"
}
```

### **🎯 Accessing Metadata**
```php
$scorm = ScormModel::find(1);

// Access manifest-extracted data
$creationDate = $scorm->getPackageCreationDate(); // From manifest
$creator = $scorm->getPackageCreator();           // From manifest
$author = $scorm->getPackageAuthor();             // Alias for creator
$size = $scorm->getPackageSize();                 // Auto-detected

// Metadata management
$allMetadata = $scorm->getAllMetadata();
$hasField = $scorm->hasMetadata('created_at');
$customValue = $scorm->getMetadata('custom_field', 'default');

// Set custom metadata
$scorm->setMetadata('custom_field', 'value');
$scorm->save();
```

### **🛡️ Enhanced Error Handling**
The package now provides better error handling and validation:

```php
try {
    $scorm = $scormManager->uploadScormArchive($file);
} catch (InvalidScormArchiveException $ex) {
    // Handle SCORM-specific errors
    $errorMessage = trans('scorm.' . $ex->getMessage());
    
    // Common error messages:
    // - 'invalid_scorm_archive_message': Invalid ZIP or missing manifest
    // - 'cannot_load_imsmanifest_message': Cannot parse manifest XML
    // - 'invalid_scorm_manifest_identifier': Missing manifest identifier
    // - 'invalid_scorm_version_message': Unsupported SCORM version
    // - 'no_sco_in_scorm_archive_message': No SCOs found in package
} catch (StorageNotFoundException $ex) {
    // Handle storage configuration errors
}
```

### **📋 Supported SCORM Versions**
- **SCORM 1.2**: Full support
- **SCORM 2004**: Full support (3rd & 4th Edition)
- **Adobe Captivate**: Special handling for nested `.cpt` folders
- **Custom Packages**: Flexible manifest location support

### **🔧 Configuration**
The package uses Laravel's filesystem configuration:

```php
// config/scorm.php
return [
    'disk' => 'local',           // Main SCORM storage disk
    'archive' => 'local',        // Archive storage disk
    'table_names' => [
        'scorm_table' => 'scorms',
        'scorm_sco_table' => 'scorm_scos',
        'scorm_sco_tracking_table' => 'scorm_sco_trackings',
    ],
];
```

### **📁 File Structure**
After upload, SCORM packages are organized as:
```
storage/scorm/
├── {uuid}/
│   ├── imsmanifest.xml
│   ├── index.html
│   └── assets/
│       ├── css/
│       ├── js/
│       └── images/
└── {another-uuid}/
    └── ...
```

