GitHub Workflow for [Alfred 2](http://www.alfredapp.com)
==============================

It works similar to the original (and removed) [GitHub command bar](https://github.com/blog/1264-introducing-the-command-bar) and to its [update](https://github.com/blog/1461-a-smarter-more-complete-y-search-bar), the keyword is "gh" (example: `gh github/gollum issues`).

With `enter` you can open the entry in your default browser. If you just want to copy the URL of a repo/user/issue, hit `cmd+c` on an entry. Hit `cmd+enter` to paste the URL to the front most app. With `shift` or `cmd+y` you can open the URL in QuickLook.

You have to login (`gh > login`) before you can use the workflow. The login uses OAuth, so you do not have to enter your credentials.

**[DOWNLOAD](http://gh01.de/alfred/github/github.alfredworkflow)**

![Workflow Screenshot](http://gh01.de/alfred/github/workflow.png)

Commands
--------

### Repo commands

* `gh user/repo`
* `gh user/repo #123`
* `gh user/repo @branch`
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
* `gh my issues`
* `gh my notifications`
* `gh my profile`
* `gh my pulls`
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
