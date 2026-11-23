# Flipsite deploy script for PHP servers

Publish your [Flipsite](https://flipsite.io) site straight to a regular web hotel, like one.com,
from the editor. You upload one file to your web hotel once. After that, every time you click
Publish in Flipsite, your site on the web hotel is updated. No FTP, no ZIP files.

**Step-by-step guide:** [Publish to a PHP server](https://flipsite.io/help/publishing/articles/publish-to-a-php-server)

## What you need

- A web hotel (shared hosting) with PHP 7.4 or newer. Almost all of them have it.
- The PHP zip extension (ZipArchive), and curl or `allow_url_fopen`. Most web hotels have them
  turned on. Test connection in the editor tells you if something is missing.

## Install

1. In the Flipsite editor, open **Settings → Publish** and choose **PHP Server**.
2. Click **Download flipsite-deploy.php**. The file has the secret key of your site in it.
3. Upload the file to the folder of your website on your web hotel, often called `public_html`,
   `www` or `htdocs`. Use the file manager of your web hotel.
4. Click **Test connection** in the editor.
5. Publish.

Don't use the `flipsite-deploy.php` of this repository as it is: it has no secret key, and it
refuses every request until it has one. Download it from the editor instead, or paste the secret
key from the editor into `$flipsiteSecret` at the top of the file.

## How it works

When you publish, Flipsite sends a request to `https://your-site/flipsite-deploy.php` with the
address of your site as a ZIP file. The script:

1. checks that the request comes from Flipsite: it is signed with the secret key of your site
   (HMAC-SHA256 of the timestamp and the body, in `X-Flipsite-Signature`) and is at most 5 minutes
   old,
2. downloads the ZIP file and checks it (MD5),
3. unpacks it in a work folder, `.flipsite-deploy`, next to your site. Files outside the folder and
   server code (`.php` and the like) are never unpacked: a Flipsite site is plain HTML, CSS and
   images,
4. when that worked, deletes the old files of your site and moves the new ones in place.

If anything goes wrong before step 4, your site is not changed. The script answers with what went
wrong, and the editor shows it.

**Test connection** sends a signed request that only checks the server: that PHP has the zip
extension, that it can write in the folder and that it can download your site. Nothing is changed.

## Files that are kept

Publishing deletes the files of the old site, but never the script itself or what is in
`$flipsiteKeep` at the top of the script:

```php
$flipsiteKeep = [
    '.htaccess',
    '.htpasswd',
    '.user.ini',
    '.well-known',
    'cgi-bin',
];
```

Add the files and folders of your web hotel that must stay, for example a folder with other
things than the site. A kept file is never replaced by one from Flipsite. The files of the site
are added to a kept folder (for example `.well-known`).

If you choose **Redirects: Apache** in Settings → Publish, Flipsite publishes your redirects in
`.htaccess`. Remove `.htaccess` from the list then, or Flipsite can't update it.

## The old script, flipsite.php

Before this script there was `flipsite.php`, without a secret key. Replace it: download
`flipsite-deploy.php` in the editor and upload it to the same folder. The next time you publish,
`flipsite.php` is deleted with the other old files.

## Help

Test connection says what is wrong and what to do. If you are stuck, see the
[guide](https://flipsite.io/help/publishing/articles/publish-to-a-php-server) or
[contact us](https://flipsite.io/contact).

This repository is a copy of `backend/publish/php-server-deploy` of the Flipsite editor, the
script is released with the editor.

## License

MIT
