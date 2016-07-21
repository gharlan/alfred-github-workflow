Changelog
=========

Version 1.4 – 2016-22-07
------------------------

* Hotkey support
* use native update mechanism of Alfred (to keep your hotkeys)
* new command `gh user/repo releases` (@altern8tif)
* cache warmup after login
* lower cpu usage in multi curl
* fixed autocomplete values in GitHub Enterprise


Version 1.3 – 2016-17-07
------------------------

**Important:** This is the last version for Alfred 2.

* Disabled updates in Alfred 2
* Updates in Alfred 3 are loaded from new location (GitHub releases)


Version 1.2 – 2016-04-17
------------------------

### Features

* New sub commands for `gh my issues/pull`: `created`, `assigned` and `mentioned`
* New help command: `gh > help`
* Longer cache lifetime


Version 1.1 – 2015-01-10
------------------------

### Features

* GitHub Enterprise support (use `ghe`)
* Commit search (`gh user/repo *hash`)

### Bugfixes

* A space after `gh` is required to avoid confusion when using commands of other workflows like `ghost`
