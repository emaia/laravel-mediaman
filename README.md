# Laravel MediaMan

MediaMan is an elegant and powerful media management package for Laravel Apps with support for painless `uploader`, virtual `collection` & automatic `conversion` plus an on demand `association` with specific broadcasting `channel`s of your app models.

MediaMan is UI agnostic and provides a fluent API to manage your app's media, which means you've total control over your media, the look & feel & a hassle-free dev experience. It's a perfect suit for your App or API server.

## In a hurry? Here's a quick example

```php
$media = MediaUploader::source($request->file->('file'))
            ->useCollection('Posts')
            ->upload();

$post = Post::find(1);
$post->attachMedia($media, 'featured-image-channel');
```

## Why Choose MediaMan? What Sets It Apart?

While many Laravel applications grapple with media and file management, I've often found solace in [Spatie's Laravel MediaLibrary](https://github.com/spatie/laravel-medialibrary). Yet, despite its capabilities, there were aspects it didn't address, features I yearned for.

**Enter MediaMan:** Sleek, lightweight, and brimming with functionality. Whether you need a straightforward solution for attaching and detaching media or the robust backbone for an extensive media manager, MediaMan adapts to your needs. And rest assured, its evolution will be guided by the ever-changing requirements of modern applications, whether they are Monolithic, API-driven, enhanced with Livewire/InertiaJS integrations, or built upon Serverless architectures.

| Comparison                         | **MediaMan**                              | Spatie              |
|------------------------------------|-------------------------------------------|---------------------|
| **Relationship type**              | **Many to many**                          | One to many         |
| **Reuse media with another model** | **Yes**                                   | No                  |
| **Multiple Disk Support**          | **Yes**                                   | No                  |
| **Channels (file tags)**           | **Yes**                                   | No                  |
| **Collections (file groups)**      | **Yes**                                   | Specific to version |
| **Auto delete media with model**   | **Yes, with options to keep**             | Yes                 |
| **Image manipulation**             | **Yes, at ease**                          | Yes                 |
| **Manipulation type**              | **Global registry**                       | Specific to a model |
| **Custom Conversion Support**      | **Yes**                                   | Limited             |

## Overview & Key concepts

There are a few key concepts that need to be understood before continuing:

* **Media**: It can be any type of file. You should specify file restrictions in your application's validation logic before you attempt to upload a file.

* **MediaUploader**: Media items are uploaded as their own entity. It does not belong to any other model in the system when it's being created, so items can be managed independently (which makes it the perfect engine for a media manager). MediaMan provides "MediaUploader" for creating records in the database & storing in the filesystem as well.

* **MediaCollection**: It can be also referred to as a group of files. Media items can be bundled to any "collection." Media & Collections will form many-to-many relation. You can create collections / virtual directories / groups of media & later on retrieve a group to check what it contains or do.

* **Association**: Media items need to be attached to a model for an association to be made. MediaMan exposes helpers to easily get the job done. Many-to-many polymorphic relationships allow any number of media to be associated with any number of other models without the need of modifying the existing database schema.

* **Channel**: It can be also referred to as a tag of files. Media items are bound to a "channel" of a model during association. Thus, you can associate multiple types of media with a model. For example, a "User" model might have an "avatar" and a "documents" media channel. If your head is spinning, simply think of "channels" as :tags" for a specific model. Channels are also needed to perform conversions.

* **Conversion**: You can manipulate images using conversions, conversions will be performed when a media item is associated with a model. For example, you can register a "thumbnail" conversion to run when images are attached to the "gallery" channel of a model.

## Table of Contents

* [Requirements](#requirements)
* [Installation](#installation)
* [Configuration](#configuration)
* [Media](#media)
* [Media & Models](#media--models)
* [Collections](#collections)
* [Media & Collections](#media--collections)
* [Media, Models & Conversions](#conversions)
* [Upgrade Guide to MediaMan v1.x](#upgrade-guide-to-mediaman-v1x)
* [Contribution and License](#contribution-and-license)

## Requirements

| Laravel Version | Package Version | PHP Version        |
|-----------------|-----------------|--------------------|
| v7              | 1.x             | 7.3 - 8.0          |
| v8              | 1.x             | 7.3 - 8.1          |
| v9              | 1.x             | 8.0 - 8.2          |
| v10             | 1.x             | 8.1 - 8.3          |
| v11             | 1.x             | 8.2 - 8.3          |

Note: From version 2.x of the Laravel Mediaman package, only Laravel 10 and higher versions will be supported; please consider upgrading as official support for previous versions has ended.

## Installation

You can install the package via composer:

```bash
composer require Emaia/laravel-mediaman
```

Laravel should auto discover the package unless you've disabled auto-discovery mode. In that case, add the service provider to your config/app.php:

`Emaia\MediaMan\MediaManServiceProvider::class`

Once installed, you should publish the provided assets to create the necessary migration and config files.

```bash
php artisan mediaman:publish-config
php artisan mediaman:publish-migration
```

Ensure the storage is linked.

```bash
php artisan storage:link
```

Run the migration and you are all set.

```bash
php artisan migrate
```

## Configuration

MediaMan works out of the box. If you want to tweak it, MediaMan ships with a `config/mediaman.php`. One common need of tweaking could be to store media in a dedicated Storage.

MediaMan supports all the storage drivers that Laravel supports (for i.e., Local, S3, SFTP, FTP, Dropbox & so on).

Here's an example configuration to use a dedicated local media disk for MediaMan.

```php
// file: config/filesystems.php
// define a new disk
'disks' => [
    ...
    'media' =>
        'driver' => 'local',
        'root' => storage_path('app/media'),
        'url' => env('APP_URL') . '/media',
        'visibility' => 'public',
    ],
]

// define the symbolic link
'links' => [
    ...
    public_path('media') => storage_path('app/media'),
],


// file: config/mediaman.php
// update the disk config to use our recently created media disk
'disk' => 'media'
```

Now, run `php artisan storage:link` to create the symbolic link of our newly created media disk.

## Media

### Upload media

You should use the `Emaia\MediaMan\MediaUploader` class to handle file uploads. You can upload, create a record in the database & store the file in the filesystem in one go.

```php
$file  = $request->file('file')
$media = MediaUploader::source($file)->upload();
```

The file will be stored in the default disk & bundled in the default collection specified in the mediaman config. The file size will be stored in the database & the file name will be sanitized automatically.

However, you can do a lot more, not just stick to the defaults.

```php
$file  = $request->file('file')
$media = MediaUploader::source($file)
            ->useName('Custom name')
            ->useFileName('custom-name.png')
            ->useCollection('Images')
            ->useDisk('media')
            ->useData([
                'additional_data' => 'will be stored as json',
                'use_associative_array' => 'to store any data you want to be with the file',
            ])
            ->upload();
```

If the collection doesn't exist, it'll be created on the fly. You can read more about the collections below.

**Q: What happens if I don't provide a unique file name in the above process?**

A: Don't worry, MediaMan manages uploading in a smart & safe way. Files are stored in the disk in a way that conflicts are barely going to happen. When storing in the disk, MediaMan will create a directory in the disk with a format of: `mediaId-hash` & put the file inside of it. Anything related to the file will have its own little house.

**Q: But why? Won't I get a bunch of directories?**

A: Yes, you'll. If you want, extend the `Emaia\MediaMan\Models\Media` model & you can customize however you like. Finally, point your customized model in the mediaman config. But we recommend sticking to the default, thus you don't need to worry about file conflicts. A hash is added along with the mediaId, hence users won't be able to guess & retrieve a random file. More on customization will be added later.

**Reminder: MediaMan treats any file (instance of `Illuminate\Http\UploadedFile`) as a media source. If you want a certain file type can be uploaded, you can use Laravel's validator.**

### Retrieve media

You can use any Eloquent operation to retrieve a media, plus we've added findByName().

```php
// by id
$media = Media::find(1);

// by name
$media = Media::findByName('media-name');

// with collections
$media = Media::with('collections')->find(1);
```

An instance of Media has the following attributes:

```php
'id' => int
'name' => string
'file_name' => string
'extension' => string
'type' => string
'mime_type' => string
'size' =>  int // in bytes
'friendly_size' => string // in human-readable format
'media_uri' => string  // media URI for the original file. Usage in Blade: {{ asset($media->media_uri) }}.
'media_url' => string  // direct URL for the original file.
'disk' =>  string
'data' => array // casts as an array
'created_at' =>  string
'updated_at' => string
'collections' => object // eloquent collection
```

You have access to some methods along with the attributes:

```php
// $media->mime_type => 'image/jpg'
$media->isOfType('image') // true

// get the media url, accepts optional '$conversionName' argument
$media->getUrl('conversion-name')

// get the path to the file on disk, accepts optional '$conversionName' argument
$media->getPath('conversion-name')

// get the directory where the media stored on disk
$media->getDirectory()
```

### Update media

With an instance of `Media`, you can perform various update operations:

```php
$media = Media::first();
$media->name = 'New name';
$media->data = ['additional_data' => 'additional data']
$media->save()
```

#### Update Media Name

```php
$media = Media::first();
$media->name = 'New Display Name';
$media->save();
```

#### Update Additional Data

```php
$media->data = ['additional_data' => 'new additional data'];
$media->save();
```

#### Remove All Additional Data

```php
$media->data = [];
$media->save();
```

#### Update Media File Name

Updating the media file name will also rename the actual file in storage.

```php
$media->file_name = 'new_filename.jpg';
$media->save();
```

#### Change Media Storage Disk

Moving the media to another storage disk will transfer the actual file to the specified disk.

```php
$media->disk = 's3';  // Example disk name, ensure it exists
$media->save();
```

**Heads Up!** There's a config regarding disk accessibility checks for read-write operations: `check_disk_accessibility`.

**Disk Accessibility Checks**:

* *Pros*: Identifies potential disk issues early on.

* *Cons*: Can introduce performance delays.

**Tip**: Enabling this check can preemptively spot storage issues but may add minor operational delays. Consider your system's needs and decide accordingly.

### Delete media

You can delete a media by calling the delete () method on an instance of Media.

```php
$media = Media::first();
$media->delete()
```

Or you delete media like this:

```php
Media::destroy(1);
Media::destroy([1, 2, 3]);
```

**Note:** When a Media instance gets deleted, a file will be removed from the filesystem, all the association with your App Models & MediaCollection will be removed as well.

**Heads Up!:** You should not delete media using queries, e.g. `Media::where('name', 'the-file')->delete()`, this will not trigger deleted event & the file won't be deleted from the filesystem. Read more about it in the [official documentation](https://laravel.com/docs/master/eloquent#deleting-models-using-queries).

-----

## Media & Models

### Associate media

MediaMan exposes easy-to-use API via `Emaia\MediaMan\HasMedia` trait for associating media items with models. Use the trait in your App Model & you are good to go.

```php
use Illuminate\Database\Eloquent\Model;
use Emaia\MediaMan\Traits\HasMedia;

class Post extends Model
{
    use HasMedia;
}
```

This will establish the relationship between your App Model and the Media Model.

Once done, you can associate media with the model as demonstrated below.

The first parameter of the `attachMedia()` method can either be a media model / id or an iterable collection of models / ids.

```php
$post = Post::first();

// Associate in the default channel
$post->attachMedia($media); // or 1 or [1, 2, 3] or collection of media models

// Associate in a custom channel
$post->attachMedia($media, 'featured-image');
```

`attachMedia()` returns number of media attached (int) on success & null on failure.

### Retrieve media of a model

Apart from that, `HasMedia` trait enables your App Models retrieving media conveniently.

```php
// All media from the default channel
$post->getMedia();

// All media from the specified channel
$post->getMedia('featured-image');
```

Though the original media URL is appended with the Media model, it's nice to know that you have a getUrl() method available.

```php
$media =  $post->getMedia('featured-image');
// getUrl() accepts only one optional argument: the name of the conversion
// leaves it empty to get the original media URL
$mediaOneUrl = $media[0]->getUrl();
```

It might be a common scenario for most of the Laravel apps to use the first media item more often; hence MediaMan has dedicated methods to retrieve the first item among all associated media.

```php
// First media item from the default channel
$post->getFirstMedia();

// First media item from the specified channel
$post->getFirstMedia('featured-image');

// URL of the first media item from the default channel
$post->getFirstMediaUrl();

// URL of the first media item from the specified channel
$post->getFirstMediaUrl('featured-image');
```

*Tip:* getFirstMediaUrl() accepts two optional arguments: channel name & conversion name

### Disassociate media

You can use `detachMedia()` method which is also shipped with HasMedia trait to disassociate media from a model.

```php
// Detach the specified media
$post->detachMedia($media); // or 1 or [1, 2, 3] or collection of media models

// Detach all media from all channels
$post->detachMedia();

// Detach all media of the default channel
$post->clearMediaChannel();

// Detach all media of the specific channel
$post->clearMediaChannel('channel-name');
```

`detachMedia()` returns number of media detached (int) on success & null on failure.

### Synchronize association / disassociation

You can sync media of a specified channel using the syncMedia() method. This provides a flexible way to maintain the association between your model and the related media records. The default method signature look like this: `syncMedia($media, string $channel = 'default', array $conversions = [], $detaching = true)`

This will remove the media that aren't in the provided list and add those which aren't already attached if $detaching is truthy.

```php
$post = Post::first();
$media = Media::find(1); // model instance or just a media id: 1, or array of id: [1, 2, 3] or a collection of media models

// Sync media in the default channel (the $post will have only $media and others will be removed)
$post->syncMedia($media);
```

**Heads Up!:** None of the attachMedia, detachMedia, or syncMedia methods deletes the file, it just does as it means. Refer to delete a media section to know how to delete a media.

-----

## Collections

MediaMan provides collections to bundle your media for better media management. Use `Emaia\MediaMan\Models\MediaCollection` to deal with media collections.

### Create a collection

Collections are created on thy fly if it doesn't exist while uploading a file.

```php
$media = MediaUploader::source($request->file('file'))
            ->useCollection('My Collection')
            ->upload();
```

If you wish to create a collection without uploading a file, you can do it; after all, it's an Eloquent model.

```php
MediaCollection::create(['name' => 'My Collection']);
```

### Retrieve a collection

You can retrieve a collection by its id or name.

```php
MediaCollection::find(1);
MediaCollection::findByName('My Collection');

// Retrieve the bound media as well
MediaCollection::with('media')->find(1);
MediaCollection::with('media')->findByName('My Collection');
```

### Update collection

You can update a collection name. It doesn't really have any other things to update.

```php
$collection = MediaCollection::findByName('My Collection');
$collection->name = 'Our Collection'
$collection->save();
```

### Delete collection

You can delete a collection using an instance of MediaCollection.

```php
$collection = MediaCollection::find(1);
$collection->delete()
```

This won't delete the media from the disk, but the bindings will be removed from a database.

*Heads Up!* deleteWithMedia() is a conceptual method that hasn't implemented yet, create a feature request if you need this. PRs are very much appreciated.

------

## Media & Collections

The relationship between `Media` & `MediaCollection` are already configured. You can bind, unbind & sync binding & unbinding easily. The method signatures are similar for `Media::**Collections()` and `MediaCollection::**Media()`.

### Bind media

```php
$collection = MediaCollection::first();
// You can pass a media model / id / name or an iterable collection of those
// e.g., 1 or [1, 2] or $media or [$mediaOne, $mediaTwo] or 'media-name' or ['media-name', 'another-media-name']
$collection->attachMedia($media);
```

`attachMedia()` returns number of media attached (int) on success & null on failure. Alternatively, you can use `Media::attachCollections()` to bind to collections from a media model instance.

*Heads Up!* Unlike `HasMedia` trait, you cannot have channels on media collections.

### Unbind media

```php
$collection = MediaCollection::first();
// You can pass a media model / id / name or an iterable collection of those
// e.g., 1 or [1, 2] or $media or [$mediaOne, $mediaTwo] or 'media-name' or ['media-name', 'another-media-name']
$collection->detachMedia($media);

// Detach all media by passing null / bool / empty-string / empty-array
$collection->detachMedia([]);
```

`detachMedia()` returns number of media detached (int) on success & null on failure. Alternatively, you can use `Media::detachCollections()` to unbind from collections from a media model instance.

### Synchronize binding & unbinding

```php
$collection = MediaCollection::first();
// You can pass media model / id / name
$collection->syncMedia($media);

// You can even pass iterable list / collection
$collection->syncMedia(Media::all())
$collection->syncMedia([1, 2, 3, 4, 5]);
$collection->syncMedia([$mediaSix, $mediaSeven]);
$collection->syncMedia(['media-name', 'another-media-name']);

// Synchronize to having zero media by passing null / bool / empty-string / empty-array
$collection->syncMedia([]);
```

`syncMedia()` always returns an array containing synchronization status. Alternatively, you can use `Media::syncCollections()` to sync with collections from a media model instance.

## Conversions

You can specify a model to perform "conversions" when a media is attached to a channel.

MediaMan provides a fluent api to manipulate images. It uses the popular [intervention/image](https://github.com/Intervention/image) library under the hood. Resizing, adding watermark, converting to a different format or anything that is supported can be done. In short, You can utilize all functionalities from the library.

Conversions are registered globally. This means that they can be reused across your application, for i.e., a Post and a User both can have the same sized thumbnail without having to register the same conversion twice.

To get started, you should first register a conversion in one of your application's service providers:

```php
use Intervention\Image\Image;
use Emaia\MediaMan\Facades\Conversion;

class AppServiceProvider extends ServiceProvider
{
    public function boot()
    {
        Conversion::register('thumb', function (Image $image) {
            // you have access to an intervention/image library,
            // perform your desired conversions here
            return $image->fit(64, 64);
        });
    }
}
```

Once you've registered a conversion, you should configure a media channel to perform the conversion when media is attached to your model.

```php
class Post extends Model
{
    use HasMedia;

    public function registerMediaChannels()
    {
        $this->addMediaChannel('gallery')
             ->performConversions('thumb');
    }
}
```

From now on, whenever a media item is attached to the "gallery" channel, a converted image will be generated. You can get the url of the converted image as demonstrated below:

```php
// getFirstMediaUrl() accepts two optional arguments: channel name & conversion name
// you should provide channel name & conversion name to get the url
$post->getFirstMediaUrl('gallery', 'thumb');
```

*Tip:* The default channel name is `default`.

```php
// if you have multiple media associated & need to retrieve URLs, you can do it with getUrl():
$media = $post->getMedia();
// getUrl() accepts only one optional argument: name of the conversion
// you should provide the conversion name to get the url
$mediaOneThumb = $media[0]->getUrl('thumb');
```

*Tip:* The `media_uri` and `media_url` are always appended with an instance of `Media`, these reflect the original file (and not the conversions).

## Upgrade Guide to MediaMan v1.x

If you're upgrading from a previous version of MediaMan, rest assured that the transition is fairly straightforward. Here's what you need to know:

### Changes

* **Introduction of `media_uri`**:
  In this release, we've introduced a new attribute called `media_uri`. This provides the URI for the original file. When you want to generate a full URL for use in Blade, you'd use it like so:

  ```blade
  {{ asset($media->media_uri) }}
  ```

* **Modification to `media_url`**:
  In previous versions, `media_url` used to act like what `media_uri` does now. Starting from v1.0.0, `media_url` will directly give you the absolute URL for the original file.

### Steps to Upgrade

1. **Update the Package**:
   Run the composer update command to get the latest version.

   ```bash
   composer update Emaia/laravel-mediaman
   ```

2. **Review Your Blade Files**:
   If you previously used `media_url` with the `asset()` helper, like:

   ```blade
   {{ asset($media->media_url) }}
   ```

   Update it to:

   ```blade
   {{ asset($media->media_uri) }}
   ```

   If you used `media_url` without `asset()`, there's no change required.

3. **Run Any New Migrations** (if applicable):
   Always check for new migrations and run them to ensure your database schema is up to date.

4. **Test Your Application**:
   As always, after an upgrade, ensure you test your application thoroughly, especially the parts where media files are used, to make sure everything works as expected.

Thank you for using MediaMan, and we hope you enjoy the improvements in v1.0.0! If you face any issues, feel free to open a ticket on GitHub.

## Contribution and License

If you encounter a bug, please consider opening an issue. Feature Requests & PRs are welcome.

The MIT License (MIT). Please read [License File](LICENSE.md) for more information.
