GitHub Workflow for [Alfred 3](http://www.alfredapp.com)
==============================

[![Gitter](https://badges.gitter.im/gharlan/alfred-github-workflow.svg)](https://gitter.im/gharlan/alfred-github-workflow?utm_source=badge&utm_medium=badge&utm_campaign=pr-badge)

## Description

After setup, will allow you to search through GitHub (`gh`) and your GitHub Enterprise instance (`ghe`).

## Setup

### Download

**[DOWNLOAD the latest workflow](https://github.com/gharlan/alfred-github-workflow/releases)**

### For github.com

In Alfred type (`gh > login`) to auth against your account. The login uses OAuth, so you do not have to enter your credentials.

### For github enterprise

1. In Alfred type (`ghe > url https://github.mycompany.com`)
2. Create a new Personal Access Token. This can be found in the settings page of your profile on your enterprise setup. It only needs access to your repos. Copy this token to your clipboard.
3. In Alfred type (`ghe > login https://github.mycompany.com <paste token here>`)
4. You can now `ghe your_enterprise_repo_name`

![Workflow Screenshot](screenshot.png)

Key Combinations
----------------

Key Combination        | Action
---------------------- | ------
`enter`                | Open entry in default browser
`cmd` + `c`            | Copy URL of the entry
`cmd` + `enter`        | Paste URL to front most app
`shift` or `cmd` + `y` | Open URL in QuickLook

Commands
--------

To search through your GitHub Enterprise instance replace `gh` by `ghe`.

### Repo commands

* `gh user/repo`
* `gh user/repo #123`
* `gh user/repo @branch`
* `gh user/repo *commit`
* `gh user/repo /path/to/file`
* `gh user/repo admin`
* `gh user/repo clone`
* `gh user/repo graphs`
* `gh user/repo issues`
* `gh user/repo milestones`
* `gh user/repo network`
* `gh user/repo new issue`
* `gh user/repo new pull`
* `gh user/repo pulls`
* `gh user/repo pulse`
* `gh user/repo releases`
* `gh user/repo wiki`

### User commands

* `gh @user`
* `gh @user contributions`
* `gh @user repositories`
* `gh @user activity`
* `gh @user stars`
* `gh @user gists`

### "My" commands

* `gh my dashboard`
* `gh my notifications`
* `gh my profile`
* `gh my issues`
* `gh my issues created`
* `gh my issues assigned`
* `gh my issues mentioned`
* `gh my pulls`
* `gh my pulls created`
* `gh my pulls assigned`
* `gh my pulls mentioned`
* `gh my settings`
* `gh my stars`
* `gh my gists`

### Workflow commands

* `gh > login`
* `gh > logout`
* `gh > delete cache`
* `gh > update`
* `gh > activate autoupdate`
* `gh > deactivate autoupdate`
* `gh > help`
* `gh > changelog`
* `ghe > url` (GitHub Enterprise only)
* `ghe > generate token` (GitHub Enterprise only)
* `ghe > enterprise reset` (GitHub Enterprise only)
