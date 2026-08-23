# harm.moe — Profile System Setup Guide

## Database Setup (Turso / libSQL)

### Connection
1. Create a Turso database at https://turso.tech
2. Get your database URL and auth token from the Turso dashboard
3. Update `api.php` and `comments.php`:
   ```php
   $TURSO_URL   = 'https://your-db-name.your-org.turso.io';
   $TURSO_TOKEN = 'your-auth-token-here';
   ```

### Required Tables

**profiles table:**
```sql
CREATE TABLE profiles (
    id TEXT PRIMARY KEY,
    display_name TEXT DEFAULT '',
    bio_lines TEXT DEFAULT '[]',
    bio_color TEXT DEFAULT '#d0d0d0',
    song_title TEXT DEFAULT '',
    song_artist TEXT DEFAULT '',
    song_url TEXT DEFAULT '',
    avatar_url TEXT DEFAULT '',
    album_art_url TEXT DEFAULT '',
    background_url TEXT DEFAULT '',
    background_type TEXT DEFAULT 'color',
    tiktok TEXT DEFAULT '',
    github TEXT DEFAULT '',
    telegram TEXT DEFAULT '',
    instagram TEXT DEFAULT '',
    spotify TEXT DEFAULT '',
    roblox TEXT DEFAULT '',
    discord TEXT DEFAULT '',
    custom_links TEXT DEFAULT '[]',
    dc_card_enabled INTEGER DEFAULT 0,
    dc_display_name TEXT DEFAULT '',
    dc_username TEXT DEFAULT '',
    dc_primary_color TEXT DEFAULT '#5865f2',
    dc_accent_color TEXT DEFAULT '#5865f2',
    dc_bio TEXT DEFAULT '',
    dc_join_date TEXT DEFAULT '',
    dc_avatar_url TEXT DEFAULT '',
    dc_banner_url TEXT DEFAULT '',
    name_color TEXT DEFAULT '#ffffff',
    name_glow_color TEXT DEFAULT '#ffffff',
    name_glow_enabled INTEGER DEFAULT 0,
    name_glow_amount REAL DEFAULT 0.5,
    social_color TEXT DEFAULT '#d2d2da',
    social_icon_color TEXT DEFAULT '#d2d2da',
    social_glow_enabled INTEGER DEFAULT 0,
    social_glow_color TEXT DEFAULT '#d2d2da',
    border_color TEXT DEFAULT 'rgba(255,255,255,0.12)',
    header_color TEXT DEFAULT '#1c1c1c',
    header_title_color TEXT DEFAULT '#ffffff',
    button_hover_color TEXT DEFAULT 'rgba(255,255,255,0.07)',
    bar_color TEXT DEFAULT 'rgba(210,210,218,0.85)',
    bar_color_left TEXT DEFAULT '',
    bar_color_right TEXT DEFAULT '',
    avatar_glow_color TEXT DEFAULT '#ffffff',
    avatar_glow_enabled INTEGER DEFAULT 0,
    avatar_glow_amount REAL DEFAULT 0.5,
    cursor_color TEXT DEFAULT '#d2d2da',
    now_playing_color TEXT DEFAULT '#b4b4b4',
    unfilled_bar_color TEXT DEFAULT 'rgba(255,255,255,0.08)',
    views_enabled INTEGER DEFAULT 1,
    views_color TEXT DEFAULT '#ffffff',
    badges TEXT DEFAULT '[]',
    profile_blur_enabled INTEGER DEFAULT 0,
    profile_blur_amount REAL DEFAULT 0.5,
    now_playing_opacity REAL DEFAULT 1.0,
    name_stroke_color TEXT DEFAULT '#000000',
    name_stroke_width REAL DEFAULT 0,
    name_drop_shadow_enabled INTEGER DEFAULT 0,
    bio_stroke_color TEXT DEFAULT '#000000',
    bio_stroke_width REAL DEFAULT 0,
    name_font TEXT DEFAULT '',
    bio_font TEXT DEFAULT '',
    view_count INTEGER DEFAULT 0
);
```

**comments table:**
```sql
CREATE TABLE comments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    profile TEXT NOT NULL,
    username TEXT NOT NULL,
    message TEXT NOT NULL,
    ip TEXT NOT NULL,
    posted_at INTEGER NOT NULL,
    hidden INTEGER DEFAULT 0
);
CREATE INDEX idx_comments_profile ON comments(profile);
CREATE INDEX idx_comments_ip_time ON comments(ip, posted_at);
```

### Seed Initial Profiles
```sql
INSERT INTO profiles (id) VALUES ('user1'), ('user2'), ('user3'), ('user4'), ('user5'), ('user6');
```

---

## Admin Authentication

### Generate Bcrypt Password Hashes
Use PHP's built-in `password_hash()` (cost 12 recommended):

**Option 1: PHP CLI**
```bash
php -r "echo password_hash('your-password', PASSWORD_BCRYPT, ['cost' => 12]) . PHP_EOL;"
```

**Option 2: PHP Script**
```php
<?php
$password = 'your-secure-password';
$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
echo $hash;
?>
```

**Option 3: Online (use trusted local tools only)**
```php
// Never use online generators for real passwords
```

### Configure Admin Accounts
Edit `admin-config.php`:
```php
$ADMIN_ACCOUNTS = [
    'user1'  => '$2b$12$...',  // paste generated hash here
    'user2'      => '$2b$12$...',
    'user3'   => '$2b$12$...',
    'user4'     => '$2b$12$...',
    'user5'    => '$2b$12$...',
    'user6'     => '$2b$12$...',
];
```

Allowed profile IDs are defined in `$ALLOWED_IDS` in both `api.php` and `comments.php`.

---

## Login Page

Access the admin login by visiting:
```
https://your-domain.com?login
```

This opens the login modal. Enter your username (must match a key in `$ADMIN_ACCOUNTS`) and password.

After successful login, a session cookie is set (expires in 1 hour). You can then access admin features:
- Profile editor (Save button in profile window)
- Comment moderation (hide/delete in comment wall)
- File uploads (avatar, background, etc.)

---

## File Uploads

Uploaded files are stored in `/uploads/{profileId}/` with naming:
```
{field}_{timestamp}_{random}.{ext}
```

Allowed fields: `avatar`, `song`, `album`, `background`, `dc_avatar`, `dc_banner`, `badge_icon`

Max size: 10MB (50MB for video backgrounds)

---

## Directory Structure
```
/uploads/           # User uploaded files (auto-created)
/files/             # Public file listing (served by files.php)
/sound/             # Music player tracks (served by sound.php)
/fonts/             # Web fonts
admin-config.php    # Admin password hashes (keep private!)
api.php             # Main profile API
comments.php        # Comments API
files.php           # File listing API
sound.php           # Music track listing
upload.php          # File upload handler
```

---

## Security Notes
- All API endpoints validate `HTTP_ORIGIN` / `HTTP_REFERER` against `$ALLOWED_HOST`
- Admin actions require valid session + matching profile ID
- Comments rate-limited: 1 per IP per hour
- File uploads validate MIME type, extension, and size
- HTTPS required in production

---
 
## Music Player — Songs & Album Covers
 
The music player loads tracks from the `/sound/` directory via `sound.php`. Each track needs:
 
1. **Audio file** — `.mp3` format (e.g., `My Song.mp3`)
2. **Album art** — `.webp` format with the **exact same base name** (e.g., `My Song.webp`)
 
### Adding a New Song
 
1. Place your `.mp3` file in `/sound/` (e.g., `/sound/My New Track.mp3`)
2. Create or find album art (square image recommended, 512x512 or 1024x1024)
3. Convert to `.webp` (use `cwebp`, online converters, or `ffmpeg`):
   ```bash
   # Using ffmpeg
   ffmpeg -i cover.jpg -vf "scale=512:512" cover.webp
   
   # Using cwebp (Google's WebP encoder)
   cwebp -q 80 cover.png -o cover.webp
   ```
4. Name the album art file **identically** to the audio file (except extension):
   ```
   /sound/My New Track.mp3
   /sound/My New Track.webp
   ```
5. Refresh the page — `sound.php` auto-discovers tracks on each request
 
### Changing an Existing Song
 
**Option A: Replace the file (keeps same title in player)**
```bash
# Overwrite the MP3
cp new-version.mp3 /sound/Existing\ Song.mp3
# Optionally update album art
cp new-cover.webp /sound/Existing\ Song.webp
```
 
**Option B: Rename + update (changes title in player)**
1. Delete or rename the old `.mp3` and `.webp`
2. Add new files with the desired title as the filename
 
### Supported Audio Formats
 
- Primary: **MP3** (`.mp3`) — required for `sound.php` listing
- Also playable via direct upload: OGG, WAV, FLAC, M4A (configured in `upload.php`)
 
### Album Art Requirements
 
- Format: **WebP** (`.webp`) — required for auto-discovery
- Aspect ratio: **Square** (1:1) works best
- Recommended size: **512×512** to **1024×1024**
- Max file size: 10MB (enforced by `upload.php`)
 
### How It Works
 
`sound.php` scans `/sound/` for `.mp3` files, looks for a matching `.webp` with the same base name, and returns:
```json
{
  "tracks": [
    {
      "title": "My New Track",
      "src": "/sound/My%20New%20Track.mp3",
      "art": "/sound/My%20New%20Track.webp",
      "has_art": true
    }
  ]
}
```
The frontend (`index.html`) consumes this and builds the playlist. Tracks are sorted alphabetically by title.
 
### Troubleshooting
 
| Issue | Fix |
|-------|-----|
| Song doesn't appear | Ensure `.mp3` extension is lowercase; check `sound.php` has read access to `/sound/` |
| Album art missing | Verify `.webp` exists with **exact same base name**; check file permissions |
| Art shows but wrong | Clear browser cache; filenames are case-sensitive on Linux servers |
| Can't upload via admin | Use the profile editor's "Upload" button for `song` and `album` fields (max 10MB) |
 
---
- [ ] Set `$TURSO_URL` and `$TURSO_TOKEN` in `api.php` and `comments.php`
- [ ] Set `$ALLOWED_HOST` to your domain in both files
- [ ] Generate bcrypt hashes and update `admin-config.php`
- [ ] Run SQL schema on Turso database
- [ ] Seed initial profile IDs
- [ ] Ensure `/uploads/` is writable by PHP
- [ ] Configure web server (Apache/Nginx) with rewrite rules (see `.htaccess`)
- [ ] Enable HTTPS