# WP CLI Project Command

A simple project "package" manager for WordPress WP CLI.

## Overview

`wp project` lets you easily manage your WordPress site by allowing you to track WordPress core and Plugin versions without having to track the actual files. Think of this like a package manager for WordPress. Keep everyone in your team in sync and keep your repo slim.

Helps you resolve issues with plugin versions, when WordPress.org stops supplying specific versions for security reasons.

Updates your `.gitignore` to keep custom plugins in the repo.

## Installing `project` the command

`wp package install git@github.com:SoleGraphics/wp-cli-project.git`

Once installed the `wp project` commands will be avilable.

## Starting a new project

```
wp project init
```

Creates a new WordPress project in the current directory. If WordPress is found in a higher directory, the project will be created in the root of that WordPress install (location of `wp-load.php`).

This will download and install WordPress, walking you through the setup process. It will even attempt to create the database if it doesn't exist already.

## Installing a project

If a project config file `wp-cli-project.json` is found in the current directory (or higher) you can install the project with this command.

```
wp project install
```

If this is the first install, many things will happen.

- download WordPress at specified version
- configure WordPress (wp-config.php, database creation)
- install WordPress (admin user creation)
- install plugins
- activate plugins

If thie project is already installed, this will attempt to sync any changes to `wp-cli-project.json` to your install. Such as new plugins, or updates.

## Saving changes to a project

If you have updated WordPress or installed or updated a plugin, saving these changes to the project will help you stay in sync with other developers on your team.

```
wp project save
```

This will store the core version and all plugin versions in the project file.

## Uninstalling a project

> NOTE: This is a destructive process!!!

If you're going to call this command, it's best to have saved the project first with `wp project save`.

- REMOVES all WordPress core files!
- REMOVES Plugins that are available for download from WordPress.org!
- Keeps custom plugins (anything not found on WordPress.org)
- Keeps must use plugin directory
- Keeps upload directory
- Keeps themes directory

You will be prompted with a list of removed files before anything is actually
