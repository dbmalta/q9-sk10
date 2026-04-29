# Setup Wizard

The setup wizard runs automatically on first visit. It walks you through configuring ScoutKeeper in your browser — no command line needed.

## Steps

### Step 1: System check

The wizard checks that your server meets the requirements. Any failed checks are shown with guidance on how to fix them. You cannot proceed until all required checks pass.

### Step 2: Database connection

Enter your database credentials:

- **Host** — usually `localhost`
- **Database name** — the database you created
- **Username** and **Password** — the database user you created

The wizard will test the connection before continuing.

### Step 3: Organisation details

Enter your organisation's name and select your country. These values can be changed later in **Admin → Settings**.

### Step 4: Administrator account

Create the first administrator login:

- Enter a name, email address, and a strong password
- This account has full access to all features
- Additional accounts can be added later in **Admin → Users & Logins**

### Step 5: Complete

The wizard creates the database tables and writes your configuration file. When it finishes, click **Go to ScoutKeeper** to log in for the first time.

!!! info
    The wizard creates a `config/config.php` file on your server. Keep this file private — it contains your database credentials.
