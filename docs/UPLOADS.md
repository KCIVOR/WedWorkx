# Supplier uploads (logo and photos)

Chatbot 2 lets a supplier upload one logo and up to 10 photos.

## Rules (set in `config/uploads.php`, overridable in `.env`)

| Setting | Default | `.env` key |
|---|---|---|
| Max size per file | 5 MB (5120 KB) | `UPLOAD_MAX_KB` |
| Logos per supplier | 1 | `UPLOAD_MAX_LOGO` |
| Photos per supplier | 10 | `UPLOAD_MAX_PHOTOS` |
| Allowed types | JPG, PNG, WebP | (fixed) |

The browser checks first for a fast message, and the server checks again (type, size and count) and is the real gate. Anything else is rejected with a message, and nothing is stored.

## Where files go

Files are saved on the `public` disk at `storage/app/public/uploads/{supplier_id}/` with a random file name, and one row per file is added to the `uploads` table (kind, path, original name, mime, size). Uploads are allowed only while the supplier's invite is valid, before payment, and before their answers are locked.

## Make the files viewable: `storage:link`

The `public` disk is served from `public/storage`. Create that link once on every environment:

```
php artisan storage:link
```

On cPanel, if the web root is not the Laravel `public` folder, point the `storage` link inside the web root at `storage/app/public` (see the deployment guide in T10).

## PHP limits

PHP must allow files at least as large as the limit above. Set both in `php.ini` (or cPanel "MultiPHP INI Editor"):

```
upload_max_filesize = 8M
post_max_size = 12M
```

If PHP rejects a file first, the supplier sees "That file could not be uploaded. It may be too large."
