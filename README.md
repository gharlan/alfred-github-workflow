GitHub Workflow for [Alfred 2](http://www.alfredapp.com)
==============================

It works almost like the [GitHub command bar](https://github.com/blog/1264-introducing-the-command-bar), the keyword is "gh" (example: `gh github/gollum issues`).

With `enter` you can open the entry in your default browser. If you just want to copy the URL of a repo/user/issue, hit `cmd+c` on an entry. Hit `cmd+enter` to paste the URL to the front most app. With `shift` or `cmd+y` you can open the URL in QuickLook.

There are some additional workflow specific commands, they begin with `>`:

* `gh > login <user>`
* `gh > logout`
* `gh > delete cache`
* `gh > update`
* `gh > activate autoupdate`
* `gh > deactivate autoupdate`

You have to login before you can use the workflow. The login command opens a dialog box for the password. The workflow does not save the plain password, only a cookie for the login.

**[DOWNLOAD](http://gh01.de/alfred/github/github.alfredworkflow)**

![Workflow Screenshot](http://gh01.de/alfred/github/workflow.png)
