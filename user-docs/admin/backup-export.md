# Backup & Export

## Backups

Go to **Admin → Backup** to create a database backup. The backup contains all member data, settings, and configuration in a compressed SQL dump.

### Scheduling backups

ScoutKeeper can run automatic backups via the cron job. Configure the backup frequency in **Admin → Settings → Backup**. Backups are stored in `/data/backups/` on your server.

!!! warning
    Backups are stored on the same server as your live data. For proper disaster recovery, download backups regularly and store them off-site.

### Downloading a backup

Click **Download** next to any backup in the list to download it to your computer.

### Restoring a backup

Restoring requires direct database access. Import the SQL file using phpMyAdmin or the MySQL CLI on your server.

## Data export

Go to **Admin → Export** to export member data as CSV. You can filter by section before exporting.
