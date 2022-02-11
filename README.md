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
Install from composer
```sh
composer require devianl2/laravel-scorm
```

## Step 2:
Run vendor publish for migration and config file
```sh
php artisan vendor:publish --provider="Peopleaps\Scorm\ScormServiceProvider"
```

## Step 3:
Migrate file to database
```sh
php artisan migrate
```

