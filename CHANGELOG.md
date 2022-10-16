Changelog
=========

Version 1.8.1 – 2022-10-16
--------------------------

### Bugfix

* Fixed version number in workflow


Version 1.8 – 2022-10-15
------------------------

### Features

* New command `gh user/repo dev` opening repo in Visual Studio Code (Codespaces)
* New command `gh user/repo discussions`
* New command `gh my repos new`
* Search issues by title (`gh user/repo #search`)
* Archived repos with prefix `[Archived]` in subtitle
* Performance improvement

### Bugfixes

* Fix result display for non-PR issue IDs (@tobias-grasse)
* Fix git clone url, use new `x-github-client://` scheme


Version 1.7.1 – 2022-01-01
--------------------------

### Bugfixes

* Fix deprecation warning when using PHP 8.1


Version 1.7 – 2021-10-26
------------------------

### Features

* Support for macOS 12 Montery: PHP is no longer pre-installed, you must install it by yourself via [Homebrew](https://brew.sh) (`brew install php`)
* Better support for Alfred 4
* new command `gh user/repo actions` (@Attsun1031)
* command `gh user/repo new issue` lands on issue template selector page (@riastrad)


Version 1.6.2 – 2018-02-13
--------------------------

### Bugfixes

* Api pagination didn't work correctly anymore (missing results from page > 2)


Version 1.6.1 – 2017-09-23
--------------------------

### Bugfixes

* Support for macOS 10.13 High Sierra
* Commit search results had wrong urls on GitHub Enterprise (@beparker)


Version 1.6 – 2017-05-07
------------------------

### Features

* new command `gh user/repo projects` (@dagio)
* new command `gh my pulls review requested` (@AeroEchelon)
* better sorting for issues (most recently updated on top) and commits (most recent on top) (@danielma)

### Bugfixes

* On macOS 10.12.5 Beta URLs didn't opened in browser anymore


Version 1.5 – 2016-12-13
------------------------

### Features

* new commands for searching repos and users globally in GitHub (`gh s repo` and `gh s @user`)
* new command `gh my repos` (@jacobkossman)
* new command `gh > delete database`
* source repos with higher priority than forks

### Bugfixes

* in some situations private repos were missing (@lxynox)
* after saving GitHub Enterprise url the workflow didn't reopen correctly
* updated user sub commands ("Activity" tab does not exist any more on GitHub)


Version 1.4.1 – 2016-22-07
--------------------------

* fixed reading environment variables (important for hotkey support)


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
