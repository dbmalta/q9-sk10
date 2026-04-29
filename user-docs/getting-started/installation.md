# Installation

## 1. Download ScoutKeeper

Download the latest release from the [GitHub releases page](https://github.com/scoutkeeper/scoutkeeper/releases). Get the `.zip` file — not the source code archive.

## 2. Create a database

In your hosting control panel (cPanel, Plesk, etc.):

1. Create a new MySQL database (e.g. `scoutkeeper`)
2. Create a database user with a strong password
3. Grant the user **all privileges** on the new database
4. Note down the database name, username, password, and host (usually `localhost`)

## 3. Upload the files

Extract the zip and upload all files to your web server. You can upload to:

- The **root** of your domain (`public_html/`) if ScoutKeeper is your main site
- A **subdirectory** (e.g. `public_html/scouts/`) if it will run alongside other sites

!!! tip
    Upload using FTP or your hosting file manager. The upload may take a few minutes due to the number of files.

## 4. Run the setup wizard

Open your browser and navigate to the URL where you uploaded the files:

- Root install: `https://yourdomain.org`
- Subdirectory install: `https://yourdomain.org/scouts`

You will be redirected automatically to the setup wizard. Continue to [Setup Wizard](setup-wizard.md).
