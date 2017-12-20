# Change Log

This file was auto-generated using the [github_changelog_generator](https://github.com/skywinder/github-changelog-generator).

More readable, curated release notes can be found at: https://github.com/platformsh/platformsh-cli/releases

## [v3.25.1](https://github.com/platformsh/platformsh-cli/tree/v3.25.1) (2017-12-14)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.25.0...v3.25.1)

**Closed issues:**

- RuntimeException: The provided cwd does not exist [\#664](https://github.com/platformsh/platformsh-cli/issues/664)

## [v3.25.0](https://github.com/platformsh/platformsh-cli/tree/v3.25.0) (2017-12-13)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.24.1...v3.25.0)

**Closed issues:**

- Support for drush 9.0.0-rc1 \(changed alias file naming convention again\) [\#655](https://github.com/platformsh/platformsh-cli/issues/655)

**Merged pull requests:**

- Save session data in the keychain on OS X [\#663](https://github.com/platformsh/platformsh-cli/pull/663) ([pjcdawkins](https://github.com/pjcdawkins))
- Implement is\_enabled on environment variables [\#662](https://github.com/platformsh/platformsh-cli/pull/662) ([pjcdawkins](https://github.com/pjcdawkins))
- Add SSH\_AGENT\_PID to auto inherited environment variables [\#661](https://github.com/platformsh/platformsh-cli/pull/661) ([pjcdawkins](https://github.com/pjcdawkins))
- Add redis-cli command [\#660](https://github.com/platformsh/platformsh-cli/pull/660) ([pjcdawkins](https://github.com/pjcdawkins))
- Extended interactive question text \(for integrations\) [\#659](https://github.com/platformsh/platformsh-cli/pull/659) ([pjcdawkins](https://github.com/pjcdawkins))

## [v3.24.1](https://github.com/platformsh/platformsh-cli/tree/v3.24.1) (2017-12-07)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.24.0...v3.24.1)

## [v3.24.0](https://github.com/platformsh/platformsh-cli/tree/v3.24.0) (2017-12-06)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.23.0...v3.24.0)

**Closed issues:**

- --exclude/--include for platform mount:download and platform mound:download [\#650](https://github.com/platformsh/platformsh-cli/issues/650)

**Merged pull requests:**

- Support Drush 9.0.0-rc1 + [\#656](https://github.com/platformsh/platformsh-cli/pull/656) ([pjcdawkins](https://github.com/pjcdawkins))
- Use Git data API [\#654](https://github.com/platformsh/platformsh-cli/pull/654) ([pjcdawkins](https://github.com/pjcdawkins))

## [v3.23.0](https://github.com/platformsh/platformsh-cli/tree/v3.23.0) (2017-11-24)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.22.3...v3.23.0)

**Merged pull requests:**

- Add excluded\_environments option for webhooks [\#652](https://github.com/platformsh/platformsh-cli/pull/652) ([pjcdawkins](https://github.com/pjcdawkins))
-  mount commands: add --exclude and --include [\#651](https://github.com/platformsh/platformsh-cli/pull/651) ([pjcdawkins](https://github.com/pjcdawkins))
- Allow projects to be identified from their public website URL [\#649](https://github.com/platformsh/platformsh-cli/pull/649) ([pjcdawkins](https://github.com/pjcdawkins))
- Support new mount style [\#648](https://github.com/platformsh/platformsh-cli/pull/648) ([pjcdawkins](https://github.com/pjcdawkins))
- Add GitLab integration support [\#647](https://github.com/platformsh/platformsh-cli/pull/647) ([pjcdawkins](https://github.com/pjcdawkins))

## [v3.22.3](https://github.com/platformsh/platformsh-cli/tree/v3.22.3) (2017-11-16)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.22.2...v3.22.3)

**Fixed bugs:**

- When db:dump command runs out of memory, it still returns status code 0 [\#644](https://github.com/platformsh/platformsh-cli/issues/644)

**Closed issues:**

- CLI stuck in a autoupdate loop and break [\#642](https://github.com/platformsh/platformsh-cli/issues/642)
- getProjectRoot\(\) should infer git ssh URL from project id parameter [\#640](https://github.com/platformsh/platformsh-cli/issues/640)

**Merged pull requests:**

- Add a timeout to the Drush aliases check [\#646](https://github.com/platformsh/platformsh-cli/pull/646) ([pjcdawkins](https://github.com/pjcdawkins))
- Fix exit code from db:dump to respect errors when --gzip \(-z\) is used [\#645](https://github.com/platformsh/platformsh-cli/pull/645) ([pjcdawkins](https://github.com/pjcdawkins))

## [v3.22.2](https://github.com/platformsh/platformsh-cli/tree/v3.22.2) (2017-11-08)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.22.0...v3.22.2)

## [v3.22.0](https://github.com/platformsh/platformsh-cli/tree/v3.22.0) (2017-11-03)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/show...v3.22.0)

**Merged pull requests:**

- Drush alias modernisation [\#637](https://github.com/platformsh/platformsh-cli/pull/637) ([pjcdawkins](https://github.com/pjcdawkins))

## [show](https://github.com/platformsh/platformsh-cli/tree/show) (2017-10-18)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.21.0...show)

## [v3.21.0](https://github.com/platformsh/platformsh-cli/tree/v3.21.0) (2017-10-18)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.20.5...v3.21.0)

**Merged pull requests:**

- Support logging in with a username [\#636](https://github.com/platformsh/platformsh-cli/pull/636) ([pjcdawkins](https://github.com/pjcdawkins))
- mount:list, mount:upload and mount:download commands [\#633](https://github.com/platformsh/platformsh-cli/pull/633) ([markushausammann](https://github.com/markushausammann))

## [v3.20.5](https://github.com/platformsh/platformsh-cli/tree/v3.20.5) (2017-10-13)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.20.4...v3.20.5)

**Closed issues:**

- Unknown SSL protocol error on login \(mac\) [\#631](https://github.com/platformsh/platformsh-cli/issues/631)

**Merged pull requests:**

- Fix infinite loop in Config::canWriteDir [\#634](https://github.com/platformsh/platformsh-cli/pull/634) ([damz](https://github.com/damz))
- Add default from address for health.email integration [\#632](https://github.com/platformsh/platformsh-cli/pull/632) ([pjcdawkins](https://github.com/pjcdawkins))
- Add --no-clone-parent option to branch command [\#630](https://github.com/platformsh/platformsh-cli/pull/630) ([pjcdawkins](https://github.com/pjcdawkins))
- GitHub integration: add flag for pull\_requests\_clone\_parent\_data [\#623](https://github.com/platformsh/platformsh-cli/pull/623) ([pjcdawkins](https://github.com/pjcdawkins))

## [v3.20.4](https://github.com/platformsh/platformsh-cli/tree/v3.20.4) (2017-09-20)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.20.3...v3.20.4)

**Closed issues:**

- project:create does not support new regions [\#629](https://github.com/platformsh/platformsh-cli/issues/629)
- Deleting an environment leaves the remote branch cached locally [\#627](https://github.com/platformsh/platformsh-cli/issues/627)

**Merged pull requests:**

- Add git prune help text when deleting a branch [\#628](https://github.com/platformsh/platformsh-cli/pull/628) ([xtfer](https://github.com/xtfer))

## [v3.20.3](https://github.com/platformsh/platformsh-cli/tree/v3.20.3) (2017-09-14)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.20.2...v3.20.3)

## [v3.20.2](https://github.com/platformsh/platformsh-cli/tree/v3.20.2) (2017-08-31)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.20.1...v3.20.2)

**Merged pull requests:**

- Check config dir existence in writability check [\#624](https://github.com/platformsh/platformsh-cli/pull/624) ([pjcdawkins](https://github.com/pjcdawkins))

## [v3.20.1](https://github.com/platformsh/platformsh-cli/tree/v3.20.1) (2017-08-23)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.20.0...v3.20.1)

**Closed issues:**

- Use local drush if applicable [\#620](https://github.com/platformsh/platformsh-cli/issues/620)
- Access/User list should display UUIDs [\#618](https://github.com/platformsh/platformsh-cli/issues/618)

**Merged pull requests:**

- Use the local Drush if possible [\#621](https://github.com/platformsh/platformsh-cli/pull/621) ([pjcdawkins](https://github.com/pjcdawkins))
- Show ID of users \(\#618\) [\#619](https://github.com/platformsh/platformsh-cli/pull/619) ([pjcdawkins](https://github.com/pjcdawkins))
- Easy install on Platform.sh environments [\#617](https://github.com/platformsh/platformsh-cli/pull/617) ([pjcdawkins](https://github.com/pjcdawkins))

## [v3.20.0](https://github.com/platformsh/platformsh-cli/tree/v3.20.0) (2017-08-14)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.19.1...v3.20.0)

**Closed issues:**

- user:remove vs user:delete [\#615](https://github.com/platformsh/platformsh-cli/issues/615)
- have platform build symlink locally for wordpress the same as drupal [\#577](https://github.com/platformsh/platformsh-cli/issues/577)

**Merged pull requests:**

- Add "health" integration types [\#616](https://github.com/platformsh/platformsh-cli/pull/616) ([pjcdawkins](https://github.com/pjcdawkins))
- Add build\_pull\_requests\_post\_merge for github integrations [\#612](https://github.com/platformsh/platformsh-cli/pull/612) ([pjcdawkins](https://github.com/pjcdawkins))

## [v3.19.1](https://github.com/platformsh/platformsh-cli/tree/v3.19.1) (2017-08-03)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.19.0...v3.19.1)

**Merged pull requests:**

- Travis update: add 7.2, fix hhvm build [\#613](https://github.com/platformsh/platformsh-cli/pull/613) ([pjcdawkins](https://github.com/pjcdawkins))
- Populate project, environment, and app from environment variables where possible [\#610](https://github.com/platformsh/platformsh-cli/pull/610) ([pjcdawkins](https://github.com/pjcdawkins))

## [v3.19.0](https://github.com/platformsh/platformsh-cli/tree/v3.19.0) (2017-07-18)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.18.0...v3.19.0)

**Merged pull requests:**

- Add -u option to push command [\#609](https://github.com/platformsh/platformsh-cli/pull/609) ([pjcdawkins](https://github.com/pjcdawkins))

## [v3.18.0](https://github.com/platformsh/platformsh-cli/tree/v3.18.0) (2017-07-05)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.17.0...v3.18.0)

**Closed issues:**

- Platform log kill leaking process [\#606](https://github.com/platformsh/platformsh-cli/issues/606)

**Merged pull requests:**

- Find SSH apps via the new pf:ssh: URLs in the API [\#608](https://github.com/platformsh/platformsh-cli/pull/608) ([pjcdawkins](https://github.com/pjcdawkins))
- Allow subscription info to be updated [\#607](https://github.com/platformsh/platformsh-cli/pull/607) ([pjcdawkins](https://github.com/pjcdawkins))

## [v3.17.0](https://github.com/platformsh/platformsh-cli/tree/v3.17.0) (2017-05-30)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.16.2...v3.17.0)

**Merged pull requests:**

- Improve default Drush aliases on cloning [\#603](https://github.com/platformsh/platformsh-cli/pull/603) ([pjcdawkins](https://github.com/pjcdawkins))

## [v3.16.2](https://github.com/platformsh/platformsh-cli/tree/v3.16.2) (2017-05-19)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.16.1...v3.16.2)

**Closed issues:**

- Allow cli flags to have default values via .platform/local/project.yaml [\#595](https://github.com/platformsh/platformsh-cli/issues/595)

## [v3.16.1](https://github.com/platformsh/platformsh-cli/tree/v3.16.1) (2017-05-12)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.16.0...v3.16.1)

**Merged pull requests:**

- When creating a relative symlink, ensure both ends are real paths, if possible [\#599](https://github.com/platformsh/platformsh-cli/pull/599) ([pjcdawkins](https://github.com/pjcdawkins))

## [v3.16.0](https://github.com/platformsh/platformsh-cli/tree/v3.16.0) (2017-05-10)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.15.4...v3.16.0)

**Merged pull requests:**

- Use starts\_at paging to load more than 10 activities [\#598](https://github.com/platformsh/platformsh-cli/pull/598) ([pjcdawkins](https://github.com/pjcdawkins))

## [v3.15.4](https://github.com/platformsh/platformsh-cli/tree/v3.15.4) (2017-05-03)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.15.3...v3.15.4)

## [v3.15.3](https://github.com/platformsh/platformsh-cli/tree/v3.15.3) (2017-05-01)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.15.2...v3.15.3)

**Closed issues:**

- "Not logged in" exception after logged in [\#596](https://github.com/platformsh/platformsh-cli/issues/596)

**Merged pull requests:**

- Do not check for updates immediately after install [\#597](https://github.com/platformsh/platformsh-cli/pull/597) ([pjcdawkins](https://github.com/pjcdawkins))

## [v3.15.2](https://github.com/platformsh/platformsh-cli/tree/v3.15.2) (2017-05-01)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.15.1...v3.15.2)

**Merged pull requests:**

- Explain that auto-provisioned certificates can't be deleted [\#593](https://github.com/platformsh/platformsh-cli/pull/593) ([pjcdawkins](https://github.com/pjcdawkins))

## [v3.15.1](https://github.com/platformsh/platformsh-cli/tree/v3.15.1) (2017-04-26)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.15.0...v3.15.1)

## [v3.15.0](https://github.com/platformsh/platformsh-cli/tree/v3.15.0) (2017-04-26)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.14.5...v3.15.0)

**Closed issues:**

- Windows SSH cannot resolve host [\#590](https://github.com/platformsh/platformsh-cli/issues/590)

**Merged pull requests:**

- Ssh issue with windows [\#592](https://github.com/platformsh/platformsh-cli/pull/592) ([Pierstoval](https://github.com/Pierstoval))
- Rely on date\_default\_timezone\_get\(\) instead of UTC fallback [\#591](https://github.com/platformsh/platformsh-cli/pull/591) ([Pierstoval](https://github.com/Pierstoval))
- Use pseudo-terminal for SSH [\#589](https://github.com/platformsh/platformsh-cli/pull/589) ([pjcdawkins](https://github.com/pjcdawkins))
- Add certificate commands [\#583](https://github.com/platformsh/platformsh-cli/pull/583) ([pjcdawkins](https://github.com/pjcdawkins))

## [v3.14.5](https://github.com/platformsh/platformsh-cli/tree/v3.14.5) (2017-04-17)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.14.4...v3.14.5)

## [v3.14.4](https://github.com/platformsh/platformsh-cli/tree/v3.14.4) (2017-04-15)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.14.3...v3.14.4)

**Merged pull requests:**

- Fix URL opening on OS X [\#586](https://github.com/platformsh/platformsh-cli/pull/586) ([pjcdawkins](https://github.com/pjcdawkins))

## [v3.14.3](https://github.com/platformsh/platformsh-cli/tree/v3.14.3) (2017-04-03)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.14.2...v3.14.3)

**Closed issues:**

- support branch name in platform environment:push [\#582](https://github.com/platformsh/platformsh-cli/issues/582)

## [v3.14.2](https://github.com/platformsh/platformsh-cli/tree/v3.14.2) (2017-03-27)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.14.1...v3.14.2)

**Closed issues:**

- Difference between first "platform build" and second "platform build" in regard to symlinks [\#578](https://github.com/platformsh/platformsh-cli/issues/578)
- `db:size` should not return 0% used when db is dead [\#576](https://github.com/platformsh/platformsh-cli/issues/576)

## [v3.14.1](https://github.com/platformsh/platformsh-cli/tree/v3.14.1) (2017-03-26)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.14.0...v3.14.1)

**Merged pull requests:**

- Exception if query fails in db:size [\#581](https://github.com/platformsh/platformsh-cli/pull/581) ([pjcdawkins](https://github.com/pjcdawkins))
- Remove "toolstack" concept, use "flavor" [\#580](https://github.com/platformsh/platformsh-cli/pull/580) ([pjcdawkins](https://github.com/pjcdawkins))
- Relative paths should not depend on files already existing [\#579](https://github.com/platformsh/platformsh-cli/pull/579) ([pjcdawkins](https://github.com/pjcdawkins))

## [v3.14.0](https://github.com/platformsh/platformsh-cli/tree/v3.14.0) (2017-03-01)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.13.0...v3.14.0)

**Merged pull requests:**

- Add a command to view a resolved route of an environment [\#574](https://github.com/platformsh/platformsh-cli/pull/574) ([pjcdawkins](https://github.com/pjcdawkins))

## [v3.13.0](https://github.com/platformsh/platformsh-cli/tree/v3.13.0) (2017-02-22)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.12.2...v3.13.0)

**Closed issues:**

- brew formulae for macOS [\#528](https://github.com/platformsh/platformsh-cli/issues/528)
- v2.13.3 - Drush aliases and web root [\#455](https://github.com/platformsh/platformsh-cli/issues/455)

**Merged pull requests:**

- Add an option to install dependencies globally [\#572](https://github.com/platformsh/platformsh-cli/pull/572) ([pjcdawkins](https://github.com/pjcdawkins))
- Local web server [\#534](https://github.com/platformsh/platformsh-cli/pull/534) ([pjcdawkins](https://github.com/pjcdawkins))
- Install build-time dependencies [\#396](https://github.com/platformsh/platformsh-cli/pull/396) ([pjcdawkins](https://github.com/pjcdawkins))

## [v3.12.2](https://github.com/platformsh/platformsh-cli/tree/v3.12.2) (2017-02-08)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.12.1...v3.12.2)

**Closed issues:**

- platform build error [\#546](https://github.com/platformsh/platformsh-cli/issues/546)
- platform sql dump questions [\#538](https://github.com/platformsh/platformsh-cli/issues/538)
- If we use github integration, we have to define --project & --environment [\#506](https://github.com/platformsh/platformsh-cli/issues/506)

## [v3.12.1](https://github.com/platformsh/platformsh-cli/tree/v3.12.1) (2017-02-02)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.12.0...v3.12.1)

**Closed issues:**

- platform local:drush-aliases --group=foo fails when run remotely [\#567](https://github.com/platformsh/platformsh-cli/issues/567)

**Merged pull requests:**

- Improve 'get' when the user does not have access to master [\#569](https://github.com/platformsh/platformsh-cli/pull/569) ([pjcdawkins](https://github.com/pjcdawkins))

## [v3.12.0](https://github.com/platformsh/platformsh-cli/tree/v3.12.0) (2017-01-21)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.11.0...v3.12.0)

**Closed issues:**

- Allow to skip/structure tables on evironment:sqldump [\#513](https://github.com/platformsh/platformsh-cli/issues/513)
- Arguments not being parsed properly [\#486](https://github.com/platformsh/platformsh-cli/issues/486)

**Merged pull requests:**

- Installer: more precise shell config files preferences [\#565](https://github.com/platformsh/platformsh-cli/pull/565) ([pjcdawkins](https://github.com/pjcdawkins))
- Add `project:set-remote` command [\#564](https://github.com/platformsh/platformsh-cli/pull/564) ([pjcdawkins](https://github.com/pjcdawkins))
- Clarify that self-update output messages are about platform.sh CLI [\#562](https://github.com/platformsh/platformsh-cli/pull/562) ([fuzzbomb](https://github.com/fuzzbomb))
- I believe there are missing double quotes there. [\#561](https://github.com/platformsh/platformsh-cli/pull/561) ([OriPekelman](https://github.com/OriPekelman))

## [v3.11.0](https://github.com/platformsh/platformsh-cli/tree/v3.11.0) (2017-01-09)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.10.2...v3.11.0)

**Merged pull requests:**

- db:dump: add more advanced options [\#558](https://github.com/platformsh/platformsh-cli/pull/558) ([pjcdawkins](https://github.com/pjcdawkins))

## [v3.10.2](https://github.com/platformsh/platformsh-cli/tree/v3.10.2) (2016-12-28)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.10.1...v3.10.2)

**Merged pull requests:**

- ActivityMonitor: hide progress bar if output is not decorated [\#556](https://github.com/platformsh/platformsh-cli/pull/556) ([pjcdawkins](https://github.com/pjcdawkins))

## [v3.10.1](https://github.com/platformsh/platformsh-cli/tree/v3.10.1) (2016-12-28)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.10.0...v3.10.1)

## [v3.10.0](https://github.com/platformsh/platformsh-cli/tree/v3.10.0) (2016-12-27)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.9.0...v3.10.0)

**Closed issues:**

- select a specific sql service name for platform sql:dump  [\#548](https://github.com/platformsh/platformsh-cli/issues/548)
- Unhandeled AccessDeniedException  [\#547](https://github.com/platformsh/platformsh-cli/issues/547)

**Merged pull requests:**

- Add push command, new SSH arguments, and DI [\#552](https://github.com/platformsh/platformsh-cli/pull/552) ([pjcdawkins](https://github.com/pjcdawkins))

## [v3.9.0](https://github.com/platformsh/platformsh-cli/tree/v3.9.0) (2016-12-14)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.8.1...v3.9.0)

**Closed issues:**

- \[Exception\] The command failed with the exit code: 1     [\#553](https://github.com/platformsh/platformsh-cli/issues/553)
- Log files missing from environments:log [\#544](https://github.com/platformsh/platformsh-cli/issues/544)

**Merged pull requests:**

- Allow choosing a database via a --relationship command line option [\#549](https://github.com/platformsh/platformsh-cli/pull/549) ([pjcdawkins](https://github.com/pjcdawkins))
- Add unix shell as requirement [\#545](https://github.com/platformsh/platformsh-cli/pull/545) ([mrkschan](https://github.com/mrkschan))
- Add commands for project-level variables [\#543](https://github.com/platformsh/platformsh-cli/pull/543) ([Crell](https://github.com/Crell))

## [v3.8.1](https://github.com/platformsh/platformsh-cli/tree/v3.8.1) (2016-11-22)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.8.0...v3.8.1)

**Merged pull requests:**

- Flag suspended projects in the list [\#537](https://github.com/platformsh/platformsh-cli/pull/537) ([pjcdawkins](https://github.com/pjcdawkins))

## [v3.8.0](https://github.com/platformsh/platformsh-cli/tree/v3.8.0) (2016-11-10)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.7.1...v3.8.0)

**Merged pull requests:**

- Add db namespace for database commands [\#535](https://github.com/platformsh/platformsh-cli/pull/535) ([pjcdawkins](https://github.com/pjcdawkins))
- Create CLI command for quick-read on SQL disk usage. [\#532](https://github.com/platformsh/platformsh-cli/pull/532) ([bbujisic](https://github.com/bbujisic))
- Allow locating projects by ID only [\#531](https://github.com/platformsh/platformsh-cli/pull/531) ([pjcdawkins](https://github.com/pjcdawkins))
- Fixed broken environment:sql command on PostgreSQL databases. [\#530](https://github.com/platformsh/platformsh-cli/pull/530) ([bbujisic](https://github.com/bbujisic))

## [v3.7.1](https://github.com/platformsh/platformsh-cli/tree/v3.7.1) (2016-11-03)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.7.0...v3.7.1)

**Merged pull requests:**

- Permit a command to continue after updating [\#529](https://github.com/platformsh/platformsh-cli/pull/529) ([pjcdawkins](https://github.com/pjcdawkins))

## [v3.7.0](https://github.com/platformsh/platformsh-cli/tree/v3.7.0) (2016-10-27)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.6.0...v3.7.0)

**Closed issues:**

- platform integration:add --fetch-branches=false still chooses true [\#511](https://github.com/platformsh/platformsh-cli/issues/511)
- Is providing a parent necessary on platform branch? [\#509](https://github.com/platformsh/platformsh-cli/issues/509)

**Merged pull requests:**

- Add --run-deploy-hooks option to build command [\#527](https://github.com/platformsh/platformsh-cli/pull/527) ([pjcdawkins](https://github.com/pjcdawkins))

## [v3.6.0](https://github.com/platformsh/platformsh-cli/tree/v3.6.0) (2016-10-21)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.5.4...v3.6.0)

**Closed issues:**

- Installation fails on Windows 10 with XAMPP [\#523](https://github.com/platformsh/platformsh-cli/issues/523)

**Merged pull requests:**

- Show alias and executable name in command synopsis [\#526](https://github.com/platformsh/platformsh-cli/pull/526) ([pjcdawkins](https://github.com/pjcdawkins))
- Expose current account info via auth:info command [\#525](https://github.com/platformsh/platformsh-cli/pull/525) ([pjcdawkins](https://github.com/pjcdawkins))
- Add --my filter to project:list [\#524](https://github.com/platformsh/platformsh-cli/pull/524) ([pjcdawkins](https://github.com/pjcdawkins))
- Allow deleting merged and inactive envs in one command [\#521](https://github.com/platformsh/platformsh-cli/pull/521) ([pjcdawkins](https://github.com/pjcdawkins))
- environment:branch to track upstream [\#520](https://github.com/platformsh/platformsh-cli/pull/520) ([hanoii](https://github.com/hanoii))
- Option for not deleting remote branch [\#519](https://github.com/platformsh/platformsh-cli/pull/519) ([hanoii](https://github.com/hanoii))

## [v3.5.4](https://github.com/platformsh/platformsh-cli/tree/v3.5.4) (2016-10-07)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.5.3...v3.5.4)

**Closed issues:**

- platform web opens wrong URL [\#518](https://github.com/platformsh/platformsh-cli/issues/518)

## [v3.5.3](https://github.com/platformsh/platformsh-cli/tree/v3.5.3) (2016-10-06)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.5.2...v3.5.3)

## [v3.5.2](https://github.com/platformsh/platformsh-cli/tree/v3.5.2) (2016-10-05)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.5.1...v3.5.2)

**Merged pull requests:**

- Allow special characters in branch command [\#517](https://github.com/platformsh/platformsh-cli/pull/517) ([pjcdawkins](https://github.com/pjcdawkins))

## [v3.5.1](https://github.com/platformsh/platformsh-cli/tree/v3.5.1) (2016-10-05)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.5.0...v3.5.1)

**Closed issues:**

- is there a way to prevent backing up local builds? [\#503](https://github.com/platformsh/platformsh-cli/issues/503)

**Merged pull requests:**

- Ensure temporary directory does not exist before Drush make [\#516](https://github.com/platformsh/platformsh-cli/pull/516) ([pjcdawkins](https://github.com/pjcdawkins))
- Improve activities batch performance [\#510](https://github.com/platformsh/platformsh-cli/pull/510) ([pjcdawkins](https://github.com/pjcdawkins))

## [v3.5.0](https://github.com/platformsh/platformsh-cli/tree/v3.5.0) (2016-09-22)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.4.1...v3.5.0)

**Merged pull requests:**

- Add experimental plan size overrides [\#508](https://github.com/platformsh/platformsh-cli/pull/508) ([Kazanir](https://github.com/Kazanir))
- Recursively display orphaned environments [\#507](https://github.com/platformsh/platformsh-cli/pull/507) ([pjcdawkins](https://github.com/pjcdawkins))
- Add 'title' filter to project:list [\#505](https://github.com/platformsh/platformsh-cli/pull/505) ([vincenzo](https://github.com/vincenzo))
- Add --no-backup option to build command [\#504](https://github.com/platformsh/platformsh-cli/pull/504) ([pjcdawkins](https://github.com/pjcdawkins))
- Refactor environment:checkout a bit [\#501](https://github.com/platformsh/platformsh-cli/pull/501) ([pjcdawkins](https://github.com/pjcdawkins))
- Add --exclude option to environment:delete [\#500](https://github.com/platformsh/platformsh-cli/pull/500) ([pjcdawkins](https://github.com/pjcdawkins))
- Provide an option to clone to the build directory [\#475](https://github.com/platformsh/platformsh-cli/pull/475) ([pjcdawkins](https://github.com/pjcdawkins))

## [v3.4.1](https://github.com/platformsh/platformsh-cli/tree/v3.4.1) (2016-08-26)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.4.0...v3.4.1)

**Merged pull requests:**

- Fix 'checkout' command for fancy branch names [\#499](https://github.com/platformsh/platformsh-cli/pull/499) ([pjcdawkins](https://github.com/pjcdawkins))

## [v3.4.0](https://github.com/platformsh/platformsh-cli/tree/v3.4.0) (2016-08-24)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.3.6...v3.4.0)

**Closed issues:**

- curl installer missing php extension checks [\#495](https://github.com/platformsh/platformsh-cli/issues/495)
- Drupal 8 projects using a composer flavor don't list Drupal cli commands [\#493](https://github.com/platformsh/platformsh-cli/issues/493)

**Merged pull requests:**

- Add domain:update and domain:get commands [\#498](https://github.com/platformsh/platformsh-cli/pull/498) ([pjcdawkins](https://github.com/pjcdawkins))
- Installer fixes for Windows [\#496](https://github.com/platformsh/platformsh-cli/pull/496) ([pjcdawkins](https://github.com/pjcdawkins))
- Add --date-fmt option to info commands [\#494](https://github.com/platformsh/platformsh-cli/pull/494) ([pjcdawkins](https://github.com/pjcdawkins))

## [v3.3.6](https://github.com/platformsh/platformsh-cli/tree/v3.3.6) (2016-08-09)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.3.5...v3.3.6)

**Merged pull requests:**

- Use a separate session ID for API tokens [\#492](https://github.com/platformsh/platformsh-cli/pull/492) ([pjcdawkins](https://github.com/pjcdawkins))
- Explicitly check the PHP version when running the CLI tool [\#489](https://github.com/platformsh/platformsh-cli/pull/489) ([Crell](https://github.com/Crell))
- Ensure the project ID is written to config after 'get' [\#488](https://github.com/platformsh/platformsh-cli/pull/488) ([pjcdawkins](https://github.com/pjcdawkins))
- Report progress during Git clone [\#487](https://github.com/platformsh/platformsh-cli/pull/487) ([pjcdawkins](https://github.com/pjcdawkins))

## [v3.3.5](https://github.com/platformsh/platformsh-cli/tree/v3.3.5) (2016-08-01)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.3.4...v3.3.5)

**Closed issues:**

- Feature request: Run original command after updating [\#479](https://github.com/platformsh/platformsh-cli/issues/479)

**Merged pull requests:**

- Remove extra parsing to find SSH/Drush commands [\#485](https://github.com/platformsh/platformsh-cli/pull/485) ([pjcdawkins](https://github.com/pjcdawkins))
- Use a direct proc\_open\(\) instead of passthru\(\) [\#484](https://github.com/platformsh/platformsh-cli/pull/484) ([pjcdawkins](https://github.com/pjcdawkins))
- Add security-checker in Travis [\#482](https://github.com/platformsh/platformsh-cli/pull/482) ([pjcdawkins](https://github.com/pjcdawkins))

## [v3.3.4](https://github.com/platformsh/platformsh-cli/tree/v3.3.4) (2016-07-08)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.3.3...v3.3.4)

**Closed issues:**

- Windows issue with Drush installed [\#477](https://github.com/platformsh/platformsh-cli/issues/477)

**Merged pull requests:**

- Assume Drush is installed if the command resolved [\#478](https://github.com/platformsh/platformsh-cli/pull/478) ([pjcdawkins](https://github.com/pjcdawkins))

## [v3.3.3](https://github.com/platformsh/platformsh-cli/tree/v3.3.3) (2016-07-05)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.3.2...v3.3.3)

## [v3.3.2](https://github.com/platformsh/platformsh-cli/tree/v3.3.2) (2016-07-04)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.3.1...v3.3.2)

## [v3.3.1](https://github.com/platformsh/platformsh-cli/tree/v3.3.1) (2016-07-03)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.3.0...v3.3.1)

**Closed issues:**

- Failed to remove read-only file \(during platform build\) [\#453](https://github.com/platformsh/platformsh-cli/issues/453)
- user:role should show all roles if --level is not provided [\#442](https://github.com/platformsh/platformsh-cli/issues/442)
- Support more styles of "mounts" - symlink to shared directory [\#434](https://github.com/platformsh/platformsh-cli/issues/434)

**Merged pull requests:**

- LocalBuild clean up [\#476](https://github.com/platformsh/platformsh-cli/pull/476) ([pjcdawkins](https://github.com/pjcdawkins))
- Show roles on all environments in user:role [\#473](https://github.com/platformsh/platformsh-cli/pull/473) ([pjcdawkins](https://github.com/pjcdawkins))

## [v3.3.0](https://github.com/platformsh/platformsh-cli/tree/v3.3.0) (2016-06-28)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.2.2...v3.3.0)

**Closed issues:**

- platform integration:update --fetch-branches 1 has no effect [\#465](https://github.com/platformsh/platformsh-cli/issues/465)
- local projects without a project.yaml file can fail to find the project root [\#440](https://github.com/platformsh/platformsh-cli/issues/440)

**Merged pull requests:**

- Allow the API token to be set via ~/.platformsh/config.yaml [\#472](https://github.com/platformsh/platformsh-cli/pull/472) ([pjcdawkins](https://github.com/pjcdawkins))
- Create a settings.local.php for Drupal even with the Composer flavor [\#471](https://github.com/platformsh/platformsh-cli/pull/471) ([pjcdawkins](https://github.com/pjcdawkins))
- Symlink all mounts, from the build to shared directories [\#470](https://github.com/platformsh/platformsh-cli/pull/470) ([pjcdawkins](https://github.com/pjcdawkins))
- Use chmod to force deleting builds even when files are read-only [\#469](https://github.com/platformsh/platformsh-cli/pull/469) ([pjcdawkins](https://github.com/pjcdawkins))
- Remove minimum Drush version check [\#464](https://github.com/platformsh/platformsh-cli/pull/464) ([pjcdawkins](https://github.com/pjcdawkins))

## [v3.2.2](https://github.com/platformsh/platformsh-cli/tree/v3.2.2) (2016-06-17)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.2.1...v3.2.2)

**Merged pull requests:**

- Ignore the blank lines from drush --version output [\#463](https://github.com/platformsh/platformsh-cli/pull/463) ([mrkschan](https://github.com/mrkschan))

## [v3.2.1](https://github.com/platformsh/platformsh-cli/tree/v3.2.1) (2016-06-13)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.2.0...v3.2.1)

## [v3.2.0](https://github.com/platformsh/platformsh-cli/tree/v3.2.0) (2016-06-13)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.1.2...v3.2.0)

**Merged pull requests:**

- Only remove the previous build when the new one completes [\#462](https://github.com/platformsh/platformsh-cli/pull/462) ([pjcdawkins](https://github.com/pjcdawkins))
- Sort projects and environments [\#459](https://github.com/platformsh/platformsh-cli/pull/459) ([pjcdawkins](https://github.com/pjcdawkins))
- Update default Drupal settings [\#458](https://github.com/platformsh/platformsh-cli/pull/458) ([pjcdawkins](https://github.com/pjcdawkins))
- Add 'platform multi' command [\#457](https://github.com/platformsh/platformsh-cli/pull/457) ([pjcdawkins](https://github.com/pjcdawkins))
- Add project:create command [\#340](https://github.com/platformsh/platformsh-cli/pull/340) ([pjcdawkins](https://github.com/pjcdawkins))

## [v3.1.2](https://github.com/platformsh/platformsh-cli/tree/v3.1.2) (2016-06-06)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.1.1...v3.1.2)

**Merged pull requests:**

- Improve environment:delete --merged [\#456](https://github.com/platformsh/platformsh-cli/pull/456) ([pjcdawkins](https://github.com/pjcdawkins))
- Ensure old build is removed properly [\#454](https://github.com/platformsh/platformsh-cli/pull/454) ([pjcdawkins](https://github.com/pjcdawkins))

## [v3.1.1](https://github.com/platformsh/platformsh-cli/tree/v3.1.1) (2016-05-30)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.1.0...v3.1.1)

**Closed issues:**

- Warn about missing pcntl extension instead of hiding tunnel commands [\#446](https://github.com/platformsh/platformsh-cli/issues/446)

## [v3.1.0](https://github.com/platformsh/platformsh-cli/tree/v3.1.0) (2016-05-30)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v2.13.3...v3.1.0)

**Merged pull requests:**

- Show tunnel commands in the list; check support at 'runtime' [\#452](https://github.com/platformsh/platformsh-cli/pull/452) ([pjcdawkins](https://github.com/pjcdawkins))

## [v2.13.3](https://github.com/platformsh/platformsh-cli/tree/v2.13.3) (2016-05-30)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/1.0.0...v2.13.3)

**Closed issues:**

- platform branch should set Platform.sh as the upstream for new local branches [\#448](https://github.com/platformsh/platformsh-cli/issues/448)

**Merged pull requests:**

- Adapt activity commands to show project activities [\#451](https://github.com/platformsh/platformsh-cli/pull/451) ([pjcdawkins](https://github.com/pjcdawkins))
- Adapt tables to the terminal width [\#450](https://github.com/platformsh/platformsh-cli/pull/450) ([pjcdawkins](https://github.com/pjcdawkins))
- Improve CliConfig coverage by forced config reset [\#445](https://github.com/platformsh/platformsh-cli/pull/445) ([pawpy](https://github.com/pawpy))
- Separate integration commands for more readable output [\#441](https://github.com/platformsh/platformsh-cli/pull/441) ([pjcdawkins](https://github.com/pjcdawkins))

## [1.0.0](https://github.com/platformsh/platformsh-cli/tree/1.0.0) (2016-04-04)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.0.7...1.0.0)

**Closed issues:**

- Too many releases? [\#437](https://github.com/platformsh/platformsh-cli/issues/437)

## [v3.0.7](https://github.com/platformsh/platformsh-cli/tree/v3.0.7) (2016-03-24)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.0.6...v3.0.7)

## [v3.0.6](https://github.com/platformsh/platformsh-cli/tree/v3.0.6) (2016-03-24)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.0.5...v3.0.6)

## [v3.0.5](https://github.com/platformsh/platformsh-cli/tree/v3.0.5) (2016-03-24)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.0.4...v3.0.5)

**Merged pull requests:**

- Support new 'locations' config style for finding the web root [\#436](https://github.com/platformsh/platformsh-cli/pull/436) ([pjcdawkins](https://github.com/pjcdawkins))
- Add another level of symlinks to help with build hooks [\#435](https://github.com/platformsh/platformsh-cli/pull/435) ([pjcdawkins](https://github.com/pjcdawkins))

## [v3.0.4](https://github.com/platformsh/platformsh-cli/tree/v3.0.4) (2016-03-23)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.0.3...v3.0.4)

**Closed issues:**

- How are you supposed to login to the cli if you chose to use google login when doing the trial? [\#432](https://github.com/platformsh/platformsh-cli/issues/432)
- Default Helpers have no output [\#412](https://github.com/platformsh/platformsh-cli/issues/412)

## [v3.0.3](https://github.com/platformsh/platformsh-cli/tree/v3.0.3) (2016-03-23)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.0.2...v3.0.3)

## [v3.0.2](https://github.com/platformsh/platformsh-cli/tree/v3.0.2) (2016-03-22)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.0.1...v3.0.2)

## [v3.0.1](https://github.com/platformsh/platformsh-cli/tree/v3.0.1) (2016-03-22)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.0.0...v3.0.1)

**Merged pull requests:**

- Switch order of making files in the Drupal profile build [\#433](https://github.com/platformsh/platformsh-cli/pull/433) ([pjcdawkins](https://github.com/pjcdawkins))
- Make helpers more helpful [\#431](https://github.com/platformsh/platformsh-cli/pull/431) ([pjcdawkins](https://github.com/pjcdawkins))

## [v3.0.0](https://github.com/platformsh/platformsh-cli/tree/v3.0.0) (2016-03-21)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.0.0-beta4...v3.0.0)

**Closed issues:**

- Update to version 2.13.0 [\#425](https://github.com/platformsh/platformsh-cli/issues/425)

## [v3.0.0-beta4](https://github.com/platformsh/platformsh-cli/tree/v3.0.0-beta4) (2016-03-12)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v2.13.2...v3.0.0-beta4)

## [v2.13.2](https://github.com/platformsh/platformsh-cli/tree/v2.13.2) (2016-03-12)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.0.0-beta3...v2.13.2)

## [v3.0.0-beta3](https://github.com/platformsh/platformsh-cli/tree/v3.0.0-beta3) (2016-03-11)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.0.0-beta2...v3.0.0-beta3)

## [v3.0.0-beta2](https://github.com/platformsh/platformsh-cli/tree/v3.0.0-beta2) (2016-03-09)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v3.0.0-beta1...v3.0.0-beta2)

## [v3.0.0-beta1](https://github.com/platformsh/platformsh-cli/tree/v3.0.0-beta1) (2016-03-08)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v2.13.1...v3.0.0-beta1)

## [v2.13.1](https://github.com/platformsh/platformsh-cli/tree/v2.13.1) (2016-03-08)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v2.13.0...v2.13.1)

**Closed issues:**

- description of environment:synchronize is vague [\#421](https://github.com/platformsh/platformsh-cli/issues/421)
- Include project name in default sql-dump filename [\#420](https://github.com/platformsh/platformsh-cli/issues/420)
- Add timestamp option in sql-dump command [\#419](https://github.com/platformsh/platformsh-cli/issues/419)
- environment:sql-dump --file option help is misleading [\#417](https://github.com/platformsh/platformsh-cli/issues/417)
- \[WIP\] 3.x.x roadmap [\#406](https://github.com/platformsh/platformsh-cli/issues/406)
- Check for new CLI versions on command run [\#339](https://github.com/platformsh/platformsh-cli/issues/339)
- Autocompletion: take advantage of upstream improvements [\#230](https://github.com/platformsh/platformsh-cli/issues/230)
- Plaform logs command [\#121](https://github.com/platformsh/platformsh-cli/issues/121)

**Merged pull requests:**

- Ask less often to migrate [\#430](https://github.com/platformsh/platformsh-cli/pull/430) ([pjcdawkins](https://github.com/pjcdawkins))
- Shorter time out for the self:update check [\#429](https://github.com/platformsh/platformsh-cli/pull/429) ([pjcdawkins](https://github.com/pjcdawkins))
- Move various names to constants.php [\#427](https://github.com/platformsh/platformsh-cli/pull/427) ([pjcdawkins](https://github.com/pjcdawkins))
- Move various names to constants.php [\#426](https://github.com/platformsh/platformsh-cli/pull/426) ([pjcdawkins](https://github.com/pjcdawkins))
- Upgrade to Symfony 3 [\#424](https://github.com/platformsh/platformsh-cli/pull/424) ([pjcdawkins](https://github.com/pjcdawkins))
- Remove deprecated 'shell' feature [\#388](https://github.com/platformsh/platformsh-cli/pull/388) ([pjcdawkins](https://github.com/pjcdawkins))

## [v2.13.0](https://github.com/platformsh/platformsh-cli/tree/v2.13.0) (2016-02-24)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v2.12.3...v2.13.0)

**Merged pull requests:**

- Include the project ID in the SQL dump filename [\#423](https://github.com/platformsh/platformsh-cli/pull/423) ([pjcdawkins](https://github.com/pjcdawkins))
- Provide more help for the synchronize command [\#422](https://github.com/platformsh/platformsh-cli/pull/422) ([pjcdawkins](https://github.com/pjcdawkins))

## [v2.12.3](https://github.com/platformsh/platformsh-cli/tree/v2.12.3) (2016-02-17)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v2.12.2...v2.12.3)

**Merged pull requests:**

- Allow customising states/events for webhook integration [\#416](https://github.com/platformsh/platformsh-cli/pull/416) ([pjcdawkins](https://github.com/pjcdawkins))

## [v2.12.2](https://github.com/platformsh/platformsh-cli/tree/v2.12.2) (2016-02-17)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v2.12.1...v2.12.2)

## [v2.12.1](https://github.com/platformsh/platformsh-cli/tree/v2.12.1) (2016-02-12)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v2.12.0...v2.12.1)

**Merged pull requests:**

- Enable SSH compression for sql-dump [\#415](https://github.com/platformsh/platformsh-cli/pull/415) ([pjcdawkins](https://github.com/pjcdawkins))

## [v2.12.0](https://github.com/platformsh/platformsh-cli/tree/v2.12.0) (2016-02-05)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v2.11.2...v2.12.0)

**Merged pull requests:**

- Support exchangeable API tokens [\#414](https://github.com/platformsh/platformsh-cli/pull/414) ([pjcdawkins](https://github.com/pjcdawkins))
- Add 'logs' command to read environment logs [\#413](https://github.com/platformsh/platformsh-cli/pull/413) ([pjcdawkins](https://github.com/pjcdawkins))

## [v2.11.2](https://github.com/platformsh/platformsh-cli/tree/v2.11.2) (2016-01-28)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v2.11.1...v2.11.2)

**Fixed bugs:**

- Parameters don't seem to be respected when creating a GitHub integration [\#409](https://github.com/platformsh/platformsh-cli/issues/409)

## [v2.11.1](https://github.com/platformsh/platformsh-cli/tree/v2.11.1) (2016-01-08)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v2.11.0...v2.11.1)

## [v2.11.0](https://github.com/platformsh/platformsh-cli/tree/v2.11.0) (2016-01-06)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v2.10.2...v2.11.0)

**Closed issues:**

- Don't symlink builds directory in out of platform build [\#408](https://github.com/platformsh/platformsh-cli/issues/408)

**Merged pull requests:**

- Allow SSH tunnels to master [\#407](https://github.com/platformsh/platformsh-cli/pull/407) ([pjcdawkins](https://github.com/pjcdawkins))
- Always fetch master if not specified in 'platform get' [\#405](https://github.com/platformsh/platformsh-cli/pull/405) ([pjcdawkins](https://github.com/pjcdawkins))
- Process submodules in 'platform get' [\#404](https://github.com/platformsh/platformsh-cli/pull/404) ([pjcdawkins](https://github.com/pjcdawkins))
- Remove automatic build from 'platform get' [\#400](https://github.com/platformsh/platformsh-cli/pull/400) ([pjcdawkins](https://github.com/pjcdawkins))

## [v2.10.2](https://github.com/platformsh/platformsh-cli/tree/v2.10.2) (2015-12-30)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v2.10.1...v2.10.2)

**Closed issues:**

- 'platform build' fails locally for profiles with Drush \<= 6.4 [\#402](https://github.com/platformsh/platformsh-cli/issues/402)

## [v2.10.1](https://github.com/platformsh/platformsh-cli/tree/v2.10.1) (2015-12-29)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v2.10.0...v2.10.1)

**Merged pull requests:**

- ssh-key:add improvements [\#403](https://github.com/platformsh/platformsh-cli/pull/403) ([pjcdawkins](https://github.com/pjcdawkins))

## [v2.10.0](https://github.com/platformsh/platformsh-cli/tree/v2.10.0) (2015-12-18)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v2.10.0-alpha1...v2.10.0)

## [v2.10.0-alpha1](https://github.com/platformsh/platformsh-cli/tree/v2.10.0-alpha1) (2015-12-15)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v2.9.2...v2.10.0-alpha1)

**Merged pull requests:**

- SSH tunnels [\#401](https://github.com/platformsh/platformsh-cli/pull/401) ([pjcdawkins](https://github.com/pjcdawkins))

## [v2.9.2](https://github.com/platformsh/platformsh-cli/tree/v2.9.2) (2015-12-14)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v2.9.1...v2.9.2)

**Closed issues:**

- RunOtherCommand and --quiet, output still enabled [\#395](https://github.com/platformsh/platformsh-cli/issues/395)
- Refresh tokens are not properly used [\#324](https://github.com/platformsh/platformsh-cli/issues/324)

**Merged pull requests:**

- Allow an $output to be passed to runOtherCommand\(\) [\#399](https://github.com/platformsh/platformsh-cli/pull/399) ([pjcdawkins](https://github.com/pjcdawkins))

## [v2.9.1](https://github.com/platformsh/platformsh-cli/tree/v2.9.1) (2015-12-12)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v2.9.0...v2.9.1)

**Merged pull requests:**

- More project URL magic [\#394](https://github.com/platformsh/platformsh-cli/pull/394) ([pjcdawkins](https://github.com/pjcdawkins))

## [v2.9.0](https://github.com/platformsh/platformsh-cli/tree/v2.9.0) (2015-12-08)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v2.8.3...v2.9.0)

**Closed issues:**

- Warn when the runtime type does not match the local environment [\#380](https://github.com/platformsh/platformsh-cli/issues/380)

**Merged pull requests:**

- Add --format option to 12 commands [\#391](https://github.com/platformsh/platformsh-cli/pull/391) ([pjcdawkins](https://github.com/pjcdawkins))
- Add app commands to list apps and read config [\#389](https://github.com/platformsh/platformsh-cli/pull/389) ([pjcdawkins](https://github.com/pjcdawkins))

## [v2.8.3](https://github.com/platformsh/platformsh-cli/tree/v2.8.3) (2015-12-03)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v2.8.2...v2.8.3)

## [v2.8.2](https://github.com/platformsh/platformsh-cli/tree/v2.8.2) (2015-12-03)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v2.8.1...v2.8.2)

**Fixed bugs:**

- Unclear error message: Unauthorized: collection\_create\_view failed permission check [\#327](https://github.com/platformsh/platformsh-cli/issues/327)

**Closed issues:**

- Nodejs stack should not result is scary error message [\#390](https://github.com/platformsh/platformsh-cli/issues/390)
- Replace herrera-io/phar-update [\#386](https://github.com/platformsh/platformsh-cli/issues/386)
- \[Exception\]  Unexpected output from command 'drush --version' [\#376](https://github.com/platformsh/platformsh-cli/issues/376)
- How to delete Master Environment from platform.sh? [\#273](https://github.com/platformsh/platformsh-cli/issues/273)
- Plugin model for stacks [\#82](https://github.com/platformsh/platformsh-cli/issues/82)

**Merged pull requests:**

- Change self-update method to remove abandoned library [\#387](https://github.com/platformsh/platformsh-cli/pull/387) ([pjcdawkins](https://github.com/pjcdawkins))

## [v2.8.1](https://github.com/platformsh/platformsh-cli/tree/v2.8.1) (2015-11-30)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v2.8.0...v2.8.1)

## [v2.8.0](https://github.com/platformsh/platformsh-cli/tree/v2.8.0) (2015-11-30)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v2.7.3...v2.8.0)

**Merged pull requests:**

- Check for updates automatically [\#385](https://github.com/platformsh/platformsh-cli/pull/385) ([pjcdawkins](https://github.com/pjcdawkins))

## [v2.7.3](https://github.com/platformsh/platformsh-cli/tree/v2.7.3) (2015-11-25)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v2.7.2...v2.7.3)

**Merged pull requests:**

- Add a node.js toolstack [\#382](https://github.com/platformsh/platformsh-cli/pull/382) ([pjcdawkins](https://github.com/pjcdawkins))
- Speed up Travis [\#381](https://github.com/platformsh/platformsh-cli/pull/381) ([pjcdawkins](https://github.com/pjcdawkins))

## [v2.7.2](https://github.com/platformsh/platformsh-cli/tree/v2.7.2) (2015-11-20)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v2.7.1...v2.7.2)

**Closed issues:**

- Local build and relative paths [\#374](https://github.com/platformsh/platformsh-cli/issues/374)
- Integrations, repositories, local deployment [\#366](https://github.com/platformsh/platformsh-cli/issues/366)

**Merged pull requests:**

- Drush make fixes take 2 [\#378](https://github.com/platformsh/platformsh-cli/pull/378) ([pjcdawkins](https://github.com/pjcdawkins))
- Drush make fixes [\#377](https://github.com/platformsh/platformsh-cli/pull/377) ([pjcdawkins](https://github.com/pjcdawkins))

## [v2.7.1](https://github.com/platformsh/platformsh-cli/tree/v2.7.1) (2015-11-19)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v2.7.0...v2.7.1)

**Closed issues:**

- `drush @pid.branch ssh` should have the --cd equal the .platform.app.yaml web:document\_root [\#370](https://github.com/platformsh/platformsh-cli/issues/370)
- Drush aliases broken in multi-app projects [\#342](https://github.com/platformsh/platformsh-cli/issues/342)
- Support SSH in multi-app [\#326](https://github.com/platformsh/platformsh-cli/issues/326)
- Detect/allow override for the drush aliases docroot [\#252](https://github.com/platformsh/platformsh-cli/issues/252)
- Support multiple apps per project [\#159](https://github.com/platformsh/platformsh-cli/issues/159)

## [v2.7.0](https://github.com/platformsh/platformsh-cli/tree/v2.7.0) (2015-11-11)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v2.6.3...v2.7.0)

**Merged pull requests:**

- Fix tests on Travis [\#372](https://github.com/platformsh/platformsh-cli/pull/372) ([pjcdawkins](https://github.com/pjcdawkins))
- Use local app structure to find multi-app information [\#371](https://github.com/platformsh/platformsh-cli/pull/371) ([pjcdawkins](https://github.com/pjcdawkins))
- New installer instructions [\#338](https://github.com/platformsh/platformsh-cli/pull/338) ([pjcdawkins](https://github.com/pjcdawkins))

## [v2.6.3](https://github.com/platformsh/platformsh-cli/tree/v2.6.3) (2015-11-10)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v2.6.2...v2.6.3)

**Closed issues:**

- SSL certificate problem - Windows 10 [\#368](https://github.com/platformsh/platformsh-cli/issues/368)
- PHP does not pick up the right PATH in some shells [\#305](https://github.com/platformsh/platformsh-cli/issues/305)

**Merged pull requests:**

- Run build hooks for 'vanilla' apps without --copy [\#369](https://github.com/platformsh/platformsh-cli/pull/369) ([pjcdawkins](https://github.com/pjcdawkins))

## [v2.6.2](https://github.com/platformsh/platformsh-cli/tree/v2.6.2) (2015-11-04)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v2.6.1...v2.6.2)

**Merged pull requests:**

- Provide all current values to the integration update request [\#367](https://github.com/platformsh/platformsh-cli/pull/367) ([pjcdawkins](https://github.com/pjcdawkins))

## [v2.6.1](https://github.com/platformsh/platformsh-cli/tree/v2.6.1) (2015-11-03)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v2.6.0...v2.6.1)

**Closed issues:**

- Drush aliases and 'platform get' to a directory more than one level removed from the current location [\#364](https://github.com/platformsh/platformsh-cli/issues/364)

## [v2.6.0](https://github.com/platformsh/platformsh-cli/tree/v2.6.0) (2015-11-03)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v2.5.6...v2.6.0)

**Closed issues:**

- Installing/Updating from own fork [\#359](https://github.com/platformsh/platformsh-cli/issues/359)
- platform environment:deactivate [\#357](https://github.com/platformsh/platformsh-cli/issues/357)

**Merged pull requests:**

- Issue \#364: put default Drush alias group through basename\(\) [\#365](https://github.com/platformsh/platformsh-cli/pull/365) ([pjcdawkins](https://github.com/pjcdawkins))
- Allow non-interactive choice about deleting branches [\#361](https://github.com/platformsh/platformsh-cli/pull/361) ([pjcdawkins](https://github.com/pjcdawkins))
- Remove --build option for environment:branch [\#360](https://github.com/platformsh/platformsh-cli/pull/360) ([pjcdawkins](https://github.com/pjcdawkins))
- View a specific relationship property [\#353](https://github.com/platformsh/platformsh-cli/pull/353) ([pjcdawkins](https://github.com/pjcdawkins))

## [v2.5.6](https://github.com/platformsh/platformsh-cli/tree/v2.5.6) (2015-10-26)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v2.5.5...v2.5.6)

**Merged pull requests:**

- Wait for multiple activities where applicable [\#356](https://github.com/platformsh/platformsh-cli/pull/356) ([pjcdawkins](https://github.com/pjcdawkins))
- Improve progress bars for activities [\#355](https://github.com/platformsh/platformsh-cli/pull/355) ([pjcdawkins](https://github.com/pjcdawkins))
- Also allow user:role to handle 'none' [\#352](https://github.com/platformsh/platformsh-cli/pull/352) ([pjcdawkins](https://github.com/pjcdawkins))

## [v2.5.5](https://github.com/platformsh/platformsh-cli/tree/v2.5.5) (2015-10-23)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v2.5.4...v2.5.5)

## [v2.5.4](https://github.com/platformsh/platformsh-cli/tree/v2.5.4) (2015-10-23)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v2.5.3...v2.5.4)

**Merged pull requests:**

- Cache user account info [\#363](https://github.com/platformsh/platformsh-cli/pull/363) ([pjcdawkins](https://github.com/pjcdawkins))
- Handle new activities returned from httpaccess/smtp/variables [\#354](https://github.com/platformsh/platformsh-cli/pull/354) ([pjcdawkins](https://github.com/pjcdawkins))
- Test with normal 'composer install' too [\#351](https://github.com/platformsh/platformsh-cli/pull/351) ([pjcdawkins](https://github.com/pjcdawkins))
- Accept full URL for GitHub integration [\#328](https://github.com/platformsh/platformsh-cli/pull/328) ([pjcdawkins](https://github.com/pjcdawkins))

## [v2.5.3](https://github.com/platformsh/platformsh-cli/tree/v2.5.3) (2015-10-12)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v2.5.2...v2.5.3)

**Merged pull requests:**

- platform url should get the HTTPS URL of an environment [\#297](https://github.com/platformsh/platformsh-cli/pull/297) ([pjcdawkins](https://github.com/pjcdawkins))

## [v2.5.2](https://github.com/platformsh/platformsh-cli/tree/v2.5.2) (2015-10-08)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v2.5.1...v2.5.2)

**Merged pull requests:**

- Show more info in HTTP exception messages [\#349](https://github.com/platformsh/platformsh-cli/pull/349) ([pjcdawkins](https://github.com/pjcdawkins))

## [v2.5.1](https://github.com/platformsh/platformsh-cli/tree/v2.5.1) (2015-10-06)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v2.5.0...v2.5.1)

## [v2.5.0](https://github.com/platformsh/platformsh-cli/tree/v2.5.0) (2015-10-04)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v2.4.6...v2.5.0)

**Merged pull requests:**

- Form library [\#347](https://github.com/platformsh/platformsh-cli/pull/347) ([pjcdawkins](https://github.com/pjcdawkins))
- Add project:delete command [\#341](https://github.com/platformsh/platformsh-cli/pull/341) ([pjcdawkins](https://github.com/pjcdawkins))

## [v2.4.6](https://github.com/platformsh/platformsh-cli/tree/v2.4.6) (2015-09-23)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v2.4.5...v2.4.6)

**Merged pull requests:**

- Clear the environments cache after a state-related error [\#346](https://github.com/platformsh/platformsh-cli/pull/346) ([pjcdawkins](https://github.com/pjcdawkins))
- Support two-factor authentication [\#345](https://github.com/platformsh/platformsh-cli/pull/345) ([pjcdawkins](https://github.com/pjcdawkins))

## [v2.4.5](https://github.com/platformsh/platformsh-cli/tree/v2.4.5) (2015-09-06)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v2.4.4...v2.4.5)

**Closed issues:**

- Remove 1.x version [\#329](https://github.com/platformsh/platformsh-cli/issues/329)

**Merged pull requests:**

- Rename 'backup' to 'snapshot' [\#337](https://github.com/platformsh/platformsh-cli/pull/337) ([pjcdawkins](https://github.com/pjcdawkins))

## [v2.4.4](https://github.com/platformsh/platformsh-cli/tree/v2.4.4) (2015-09-01)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v2.4.3...v2.4.4)

**Closed issues:**

- add/modify role for environment [\#333](https://github.com/platformsh/platformsh-cli/issues/333)
- Include examples in Markdown-formatted help [\#331](https://github.com/platformsh/platformsh-cli/issues/331)
- Feature Request: Drush Aliases use Project Folder name when "getting" [\#330](https://github.com/platformsh/platformsh-cli/issues/330)

**Merged pull requests:**

- Add --no-inactive option to environment:list [\#336](https://github.com/platformsh/platformsh-cli/pull/336) ([pjcdawkins](https://github.com/pjcdawkins))
- Allow listing more than 10 backups [\#334](https://github.com/platformsh/platformsh-cli/pull/334) ([pjcdawkins](https://github.com/pjcdawkins))
- Show examples in MD help [\#332](https://github.com/platformsh/platformsh-cli/pull/332) ([pjcdawkins](https://github.com/pjcdawkins))

## [v2.4.3](https://github.com/platformsh/platformsh-cli/tree/v2.4.3) (2015-08-21)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v2.4.2...v2.4.3)

## [v2.4.2](https://github.com/platformsh/platformsh-cli/tree/v2.4.2) (2015-08-21)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v2.4.1...v2.4.2)

**Fixed bugs:**

- platform-cli seems to cache project list [\#170](https://github.com/platformsh/platformsh-cli/issues/170)

**Closed issues:**

- Advice needed "$HOME/.composer/vendor/bin:$PATH" already exists in Shell configuration file [\#323](https://github.com/platformsh/platformsh-cli/issues/323)
- SSH key UX [\#74](https://github.com/platformsh/platformsh-cli/issues/74)

**Merged pull requests:**

- Switch to accounts URL [\#307](https://github.com/platformsh/platformsh-cli/pull/307) ([pjcdawkins](https://github.com/pjcdawkins))

## [v2.4.1](https://github.com/platformsh/platformsh-cli/tree/v2.4.1) (2015-07-30)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v2.4.0...v2.4.1)

## [v2.4.0](https://github.com/platformsh/platformsh-cli/tree/v2.4.0) (2015-07-25)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v2.3.2...v2.4.0)

**Closed issues:**

- Handle 401 gracefully [\#285](https://github.com/platformsh/platformsh-cli/issues/285)

**Merged pull requests:**

- Improve 'platform get' UX [\#322](https://github.com/platformsh/platformsh-cli/pull/322) ([pjcdawkins](https://github.com/pjcdawkins))
- Set --sites-subdir=default on remote aliases [\#321](https://github.com/platformsh/platformsh-cli/pull/321) ([pjcdawkins](https://github.com/pjcdawkins))

## [v2.3.2](https://github.com/platformsh/platformsh-cli/tree/v2.3.2) (2015-07-14)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v2.3.1...v2.3.2)

## [v2.3.1](https://github.com/platformsh/platformsh-cli/tree/v2.3.1) (2015-07-11)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v2.3.0...v2.3.1)

**Merged pull requests:**

- Support new config format for type/flavor [\#320](https://github.com/platformsh/platformsh-cli/pull/320) ([pjcdawkins](https://github.com/pjcdawkins))

## [v2.3.0](https://github.com/platformsh/platformsh-cli/tree/v2.3.0) (2015-07-07)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v2.2.0...v2.3.0)

**Closed issues:**

- Shell configuration file not reloeaded automatically [\#318](https://github.com/platformsh/platformsh-cli/issues/318)

**Merged pull requests:**

- Fix: default composer build dir should be /public not / [\#316](https://github.com/platformsh/platformsh-cli/pull/316) ([pjcdawkins](https://github.com/pjcdawkins))
- Support YAML make files [\#291](https://github.com/platformsh/platformsh-cli/pull/291) ([pjcdawkins](https://github.com/pjcdawkins))

## [v2.2.0](https://github.com/platformsh/platformsh-cli/tree/v2.2.0) (2015-07-02)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v2.1.1...v2.2.0)

**Closed issues:**

- Github integration with repositories with a . in title does not work [\#314](https://github.com/platformsh/platformsh-cli/issues/314)

**Merged pull requests:**

- Box Project-based installer [\#312](https://github.com/platformsh/platformsh-cli/pull/312) ([pjcdawkins](https://github.com/pjcdawkins))

## [v2.1.1](https://github.com/platformsh/platformsh-cli/tree/v2.1.1) (2015-06-26)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v2.1.0...v2.1.1)

**Merged pull requests:**

- Show examples in command help [\#311](https://github.com/platformsh/platformsh-cli/pull/311) ([pjcdawkins](https://github.com/pjcdawkins))
- Improve domain:add [\#309](https://github.com/platformsh/platformsh-cli/pull/309) ([pjcdawkins](https://github.com/pjcdawkins))

## [v2.1.0](https://github.com/platformsh/platformsh-cli/tree/v2.1.0) (2015-06-22)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v2.0.7...v2.1.0)

**Closed issues:**

- Allow building without symlinks [\#299](https://github.com/platformsh/platformsh-cli/issues/299)
- Access to Plataform.sh behind a proxy [\#294](https://github.com/platformsh/platformsh-cli/issues/294)
- Advise Windows users about symlink permissions [\#276](https://github.com/platformsh/platformsh-cli/issues/276)
- Add a domain:update command [\#239](https://github.com/platformsh/platformsh-cli/issues/239)

**Merged pull requests:**

- Allow a custom source and destination for builds [\#308](https://github.com/platformsh/platformsh-cli/pull/308) ([pjcdawkins](https://github.com/pjcdawkins))
- Write all non-essential output to stderr [\#306](https://github.com/platformsh/platformsh-cli/pull/306) ([pjcdawkins](https://github.com/pjcdawkins))
- Allow building without symlinks [\#302](https://github.com/platformsh/platformsh-cli/pull/302) ([pjcdawkins](https://github.com/pjcdawkins))

## [v2.0.7](https://github.com/platformsh/platformsh-cli/tree/v2.0.7) (2015-06-11)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v1.10.8...v2.0.7)

**Merged pull requests:**

- Change default DB host from localhost to 127.0.0.1 [\#301](https://github.com/platformsh/platformsh-cli/pull/301) ([pjcdawkins](https://github.com/pjcdawkins))

## [v1.10.8](https://github.com/platformsh/platformsh-cli/tree/v1.10.8) (2015-06-11)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v2.0.6...v1.10.8)

**Merged pull requests:**

- Proxy support [\#298](https://github.com/platformsh/platformsh-cli/pull/298) ([pjcdawkins](https://github.com/pjcdawkins))

## [v2.0.6](https://github.com/platformsh/platformsh-cli/tree/v2.0.6) (2015-06-06)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v2.0.5...v2.0.6)

**Closed issues:**

- Error message when tunnel creation fails [\#91](https://github.com/platformsh/platformsh-cli/issues/91)

**Merged pull requests:**

- Fix running Composer on Windows [\#304](https://github.com/platformsh/platformsh-cli/pull/304) ([pjcdawkins](https://github.com/pjcdawkins))
- Don't check out a branch locally if the Platform.sh branching fails [\#303](https://github.com/platformsh/platformsh-cli/pull/303) ([pjcdawkins](https://github.com/pjcdawkins))
- Use the new $PLATFORM\_DOCUMENT\_ROOT env var [\#284](https://github.com/platformsh/platformsh-cli/pull/284) ([pjcdawkins](https://github.com/pjcdawkins))

## [v2.0.5](https://github.com/platformsh/platformsh-cli/tree/v2.0.5) (2015-06-01)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v2.0.4...v2.0.5)

**Closed issues:**

- Unable to install the CLI [\#300](https://github.com/platformsh/platformsh-cli/issues/300)

## [v2.0.4](https://github.com/platformsh/platformsh-cli/tree/v2.0.4) (2015-05-20)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v2.0.3...v2.0.4)

**Merged pull requests:**

- Support API tokens [\#293](https://github.com/platformsh/platformsh-cli/pull/293) ([pjcdawkins](https://github.com/pjcdawkins))

## [v2.0.3](https://github.com/platformsh/platformsh-cli/tree/v2.0.3) (2015-05-18)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v2.0.2...v2.0.3)

**Closed issues:**

- Detect does not seem to run on  toolstack php:symfony [\#289](https://github.com/platformsh/platformsh-cli/issues/289)

**Merged pull requests:**

- Check again for the existence of composer.json [\#290](https://github.com/platformsh/platformsh-cli/pull/290) ([pjcdawkins](https://github.com/pjcdawkins))
- Add --host option to bypass projects list check [\#288](https://github.com/platformsh/platformsh-cli/pull/288) ([pjcdawkins](https://github.com/pjcdawkins))
- Standard exception messages/codes [\#287](https://github.com/platformsh/platformsh-cli/pull/287) ([pjcdawkins](https://github.com/pjcdawkins))
- Add environment:set-remote command [\#286](https://github.com/platformsh/platformsh-cli/pull/286) ([pjcdawkins](https://github.com/pjcdawkins))

## [v2.0.2](https://github.com/platformsh/platformsh-cli/tree/v2.0.2) (2015-05-10)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v2.0.1...v2.0.2)

## [v2.0.1](https://github.com/platformsh/platformsh-cli/tree/v2.0.1) (2015-05-10)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v2.0.0...v2.0.1)

**Merged pull requests:**

- Refactor cache [\#283](https://github.com/platformsh/platformsh-cli/pull/283) ([pjcdawkins](https://github.com/pjcdawkins))
- Improve auto-completion [\#282](https://github.com/platformsh/platformsh-cli/pull/282) ([pjcdawkins](https://github.com/pjcdawkins))

## [v2.0.0](https://github.com/platformsh/platformsh-cli/tree/v2.0.0) (2015-05-05)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v1.10.7...v2.0.0)

**Closed issues:**

- Unable to install project locally on Ubuntu 14.04 [\#281](https://github.com/platformsh/platformsh-cli/issues/281)
- Token error when access expires [\#280](https://github.com/platformsh/platformsh-cli/issues/280)

## [v1.10.7](https://github.com/platformsh/platformsh-cli/tree/v1.10.7) (2015-05-01)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v2.0.0-alpha6...v1.10.7)

**Closed issues:**

- Run builds and build hooks in an equivalent directory structure to Platform.sh [\#243](https://github.com/platformsh/platformsh-cli/issues/243)
- Regression - activate, delete, and deactivate commands should refresh environments list if environments are not found [\#217](https://github.com/platformsh/platformsh-cli/issues/217)

## [v2.0.0-alpha6](https://github.com/platformsh/platformsh-cli/tree/v2.0.0-alpha6) (2015-04-28)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v2.0.0-alpha5...v2.0.0-alpha6)

## [v2.0.0-alpha5](https://github.com/platformsh/platformsh-cli/tree/v2.0.0-alpha5) (2015-04-20)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v2.0.0-alpha4...v2.0.0-alpha5)

**Merged pull requests:**

- Add subscription:metadata command [\#279](https://github.com/platformsh/platformsh-cli/pull/279) ([pjcdawkins](https://github.com/pjcdawkins))
- Functional tests for the toolstack [\#278](https://github.com/platformsh/platformsh-cli/pull/278) ([pjcdawkins](https://github.com/pjcdawkins))
- Ensure a Git remote named 'platform'. [\#198](https://github.com/platformsh/platformsh-cli/pull/198) ([pjcdawkins](https://github.com/pjcdawkins))

## [v2.0.0-alpha4](https://github.com/platformsh/platformsh-cli/tree/v2.0.0-alpha4) (2015-04-15)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v1.10.6...v2.0.0-alpha4)

**Closed issues:**

- Trying to migrate an existing site to Platform.sh. [\#272](https://github.com/platformsh/platformsh-cli/issues/272)
- Trying to set up my local development environment. [\#271](https://github.com/platformsh/platformsh-cli/issues/271)

**Merged pull requests:**

- Add local:dir command, and Bash alias 'plgit' [\#277](https://github.com/platformsh/platformsh-cli/pull/277) ([pjcdawkins](https://github.com/pjcdawkins))
- Add user commands [\#274](https://github.com/platformsh/platformsh-cli/pull/274) ([pjcdawkins](https://github.com/pjcdawkins))
- Create a local: command namespace [\#265](https://github.com/platformsh/platformsh-cli/pull/265) ([pjcdawkins](https://github.com/pjcdawkins))
- Add 'routes' command [\#264](https://github.com/platformsh/platformsh-cli/pull/264) ([pjcdawkins](https://github.com/pjcdawkins))

## [v1.10.6](https://github.com/platformsh/platformsh-cli/tree/v1.10.6) (2015-04-03)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v2.0.0-alpha3...v1.10.6)

**Merged pull requests:**

- Support BitBucket integration [\#270](https://github.com/platformsh/platformsh-cli/pull/270) ([pjcdawkins](https://github.com/pjcdawkins))

## [v2.0.0-alpha3](https://github.com/platformsh/platformsh-cli/tree/v2.0.0-alpha3) (2015-03-30)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v1.10.5...v2.0.0-alpha3)

## [v1.10.5](https://github.com/platformsh/platformsh-cli/tree/v1.10.5) (2015-03-30)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v2.0.0-alpha2...v1.10.5)

**Closed issues:**

- Drupal toolstack hard-coded document root [\#268](https://github.com/platformsh/platformsh-cli/issues/268)

**Merged pull requests:**

- Respect the document\_root setting [\#269](https://github.com/platformsh/platformsh-cli/pull/269) ([pjcdawkins](https://github.com/pjcdawkins))

## [v2.0.0-alpha2](https://github.com/platformsh/platformsh-cli/tree/v2.0.0-alpha2) (2015-03-26)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v2.0.0-alpha1...v2.0.0-alpha2)

**Closed issues:**

- Trying to install the latest stable version of the CLI using "composer global require 'platformsh/cli:@stable' " command. [\#266](https://github.com/platformsh/platformsh-cli/issues/266)

**Merged pull requests:**

- Support postgres in SQL commands [\#262](https://github.com/platformsh/platformsh-cli/pull/262) ([pjcdawkins](https://github.com/pjcdawkins))

## [v2.0.0-alpha1](https://github.com/platformsh/platformsh-cli/tree/v2.0.0-alpha1) (2015-03-25)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v1.10.4...v2.0.0-alpha1)

## [v1.10.4](https://github.com/platformsh/platformsh-cli/tree/v1.10.4) (2015-03-25)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v1.10.3...v1.10.4)

## [v1.10.3](https://github.com/platformsh/platformsh-cli/tree/v1.10.3) (2015-03-24)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v1.10.2...v1.10.3)

**Merged pull requests:**

- Create Drush aliases on 'platform get' [\#258](https://github.com/platformsh/platformsh-cli/pull/258) ([pjcdawkins](https://github.com/pjcdawkins))
- Add sql and sql-dump commands [\#255](https://github.com/platformsh/platformsh-cli/pull/255) ([pjcdawkins](https://github.com/pjcdawkins))

## [v1.10.2](https://github.com/platformsh/platformsh-cli/tree/v1.10.2) (2015-03-16)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v1.10.1...v1.10.2)

## [v1.10.1](https://github.com/platformsh/platformsh-cli/tree/v1.10.1) (2015-03-16)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v1.10.0...v1.10.1)

**Closed issues:**

- Launch project:clean with bad keep option value [\#253](https://github.com/platformsh/platformsh-cli/issues/253)
- Synchronize command should wait for the proper status [\#248](https://github.com/platformsh/platformsh-cli/issues/248)
- Build: allow to change the --contrib-destination [\#129](https://github.com/platformsh/platformsh-cli/issues/129)

## [v1.10.0](https://github.com/platformsh/platformsh-cli/tree/v1.10.0) (2015-03-15)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v1.9.3...v1.10.0)

**Merged pull requests:**

- Backport waiting from 2.x [\#254](https://github.com/platformsh/platformsh-cli/pull/254) ([pjcdawkins](https://github.com/pjcdawkins))

## [v1.9.3](https://github.com/platformsh/platformsh-cli/tree/v1.9.3) (2015-03-13)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v1.9.2...v1.9.3)

## [v1.9.2](https://github.com/platformsh/platformsh-cli/tree/v1.9.2) (2015-03-11)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v1.9.1...v1.9.2)

**Closed issues:**

- Preserve settings.local.php on rebuild after upgrading [\#249](https://github.com/platformsh/platformsh-cli/issues/249)

**Merged pull requests:**

- Fix \#249 avoid settings.local.php being overriden after an upgrade [\#250](https://github.com/platformsh/platformsh-cli/pull/250) ([DuaelFr](https://github.com/DuaelFr))

## [v1.9.1](https://github.com/platformsh/platformsh-cli/tree/v1.9.1) (2015-03-10)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v1.9.0...v1.9.1)

**Closed issues:**

- Not all admin users can create domains [\#234](https://github.com/platformsh/platformsh-cli/issues/234)
- Support .platform.app.local.yaml, to define CLI-specific build hooks [\#232](https://github.com/platformsh/platformsh-cli/issues/232)
- Add the environment:restore command when a backup is available [\#215](https://github.com/platformsh/platformsh-cli/issues/215)
- Support long-running tasks [\#109](https://github.com/platformsh/platformsh-cli/issues/109)
- All commands should probably provide a non interactive mode [\#103](https://github.com/platformsh/platformsh-cli/issues/103)
- Add a  ``--wait`` parameter to all operations [\#58](https://github.com/platformsh/platformsh-cli/issues/58)
- Make unauthorized error less scary and more helpful [\#14](https://github.com/platformsh/platformsh-cli/issues/14)

**Merged pull requests:**

- Remove deactivate command [\#263](https://github.com/platformsh/platformsh-cli/pull/263) ([pjcdawkins](https://github.com/pjcdawkins))
- Don't build in the repository for profile mode [\#244](https://github.com/platformsh/platformsh-cli/pull/244) ([pjcdawkins](https://github.com/pjcdawkins))

## [v1.9.0](https://github.com/platformsh/platformsh-cli/tree/v1.9.0) (2015-03-03)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v1.8.5...v1.9.0)

**Merged pull requests:**

- Add restore command [\#224](https://github.com/platformsh/platformsh-cli/pull/224) ([pjcdawkins](https://github.com/pjcdawkins))

## [v1.8.5](https://github.com/platformsh/platformsh-cli/tree/v1.8.5) (2015-03-02)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v1.8.4...v1.8.5)

**Merged pull requests:**

- Environment metadata: documenting available properties [\#242](https://github.com/platformsh/platformsh-cli/pull/242) ([kotnik](https://github.com/kotnik))

## [v1.8.4](https://github.com/platformsh/platformsh-cli/tree/v1.8.4) (2015-02-17)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v1.8.3...v1.8.4)

**Closed issues:**

- Do not ignore all hidden files by default. [\#236](https://github.com/platformsh/platformsh-cli/issues/236)
- Regression for platform get [\#235](https://github.com/platformsh/platformsh-cli/issues/235)

**Merged pull requests:**

- Accommodate apps without 'toolstacks' in build command. [\#238](https://github.com/platformsh/platformsh-cli/pull/238) ([pjcdawkins](https://github.com/pjcdawkins))
- Invalidate projects/environments cache after a set ttl [\#231](https://github.com/platformsh/platformsh-cli/pull/231) ([pjcdawkins](https://github.com/pjcdawkins))

## [v1.8.3](https://github.com/platformsh/platformsh-cli/tree/v1.8.3) (2015-02-10)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v1.8.2...v1.8.3)

## [v1.8.2](https://github.com/platformsh/platformsh-cli/tree/v1.8.2) (2015-02-06)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v1.8.1...v1.8.2)

## [v1.8.1](https://github.com/platformsh/platformsh-cli/tree/v1.8.1) (2015-02-05)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v1.8.0...v1.8.1)

## [v1.8.0](https://github.com/platformsh/platformsh-cli/tree/v1.8.0) (2015-02-05)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v1.8.0-alpha2...v1.8.0)

**Merged pull requests:**

- Warn about non-ignored settings.local.php [\#229](https://github.com/platformsh/platformsh-cli/pull/229) ([pjcdawkins](https://github.com/pjcdawkins))

## [v1.8.0-alpha2](https://github.com/platformsh/platformsh-cli/tree/v1.8.0-alpha2) (2015-02-04)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v1.7.5...v1.8.0-alpha2)

**Merged pull requests:**

- Auto-refresh the activity log. [\#226](https://github.com/platformsh/platformsh-cli/pull/226) ([pjcdawkins](https://github.com/pjcdawkins))
- Add environment:metadata command [\#202](https://github.com/platformsh/platformsh-cli/pull/202) ([pjcdawkins](https://github.com/pjcdawkins))

## [v1.7.5](https://github.com/platformsh/platformsh-cli/tree/v1.7.5) (2015-02-03)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v1.8.0-alpha1...v1.7.5)

## [v1.8.0-alpha1](https://github.com/platformsh/platformsh-cli/tree/v1.8.0-alpha1) (2015-02-02)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v1.7.4...v1.8.0-alpha1)

**Merged pull requests:**

- add support for generic webhook [\#225](https://github.com/platformsh/platformsh-cli/pull/225) ([OriPekelman](https://github.com/OriPekelman))
- Display the command name and aliases in the command's help. [\#223](https://github.com/platformsh/platformsh-cli/pull/223) ([pjcdawkins](https://github.com/pjcdawkins))
- Check for vanilla Drupal without requiring COPYRIGHT.txt [\#220](https://github.com/platformsh/platformsh-cli/pull/220) ([pjcdawkins](https://github.com/pjcdawkins))
- Allow the user to specify which app\(s\) to build [\#219](https://github.com/platformsh/platformsh-cli/pull/219) ([pjcdawkins](https://github.com/pjcdawkins))
- Install Drush automatically when required, if possible [\#218](https://github.com/platformsh/platformsh-cli/pull/218) ([pjcdawkins](https://github.com/pjcdawkins))
- Generate an SSH key for the user [\#214](https://github.com/platformsh/platformsh-cli/pull/214) ([pjcdawkins](https://github.com/pjcdawkins))

## [v1.7.4](https://github.com/platformsh/platformsh-cli/tree/v1.7.4) (2015-01-30)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v1.7.3...v1.7.4)

**Merged pull requests:**

- \[WIP\] Allow SSH/drush into a specific remote app [\#222](https://github.com/platformsh/platformsh-cli/pull/222) ([pjcdawkins](https://github.com/pjcdawkins))
- Integration commands [\#211](https://github.com/platformsh/platformsh-cli/pull/211) ([pjcdawkins](https://github.com/pjcdawkins))
- Add environment:activities command. [\#177](https://github.com/platformsh/platformsh-cli/pull/177) ([pjcdawkins](https://github.com/pjcdawkins))

## [v1.7.3](https://github.com/platformsh/platformsh-cli/tree/v1.7.3) (2015-01-28)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v1.7.2...v1.7.3)

**Merged pull requests:**

- Show more iformation in the welcome message for current project [\#216](https://github.com/platformsh/platformsh-cli/pull/216) ([andyg5000](https://github.com/andyg5000))

## [v1.7.2](https://github.com/platformsh/platformsh-cli/tree/v1.7.2) (2015-01-21)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v1.7.1...v1.7.2)

## [v1.7.1](https://github.com/platformsh/platformsh-cli/tree/v1.7.1) (2015-01-20)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v1.7.0...v1.7.1)

## [v1.7.0](https://github.com/platformsh/platformsh-cli/tree/v1.7.0) (2015-01-20)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v1.7.0-alpha2...v1.7.0)

## [v1.7.0-alpha2](https://github.com/platformsh/platformsh-cli/tree/v1.7.0-alpha2) (2015-01-19)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v1.7.0-alpha1...v1.7.0-alpha2)

**Closed issues:**

- Split up local shared resources by application \(RFC\) [\#212](https://github.com/platformsh/platformsh-cli/issues/212)
- Implement a 'tree id' to save build time [\#179](https://github.com/platformsh/platformsh-cli/issues/179)

**Merged pull requests:**

- Refactoring for local project/filesystem info [\#213](https://github.com/platformsh/platformsh-cli/pull/213) ([pjcdawkins](https://github.com/pjcdawkins))
- Build multiple apps [\#163](https://github.com/platformsh/platformsh-cli/pull/163) ([pjcdawkins](https://github.com/pjcdawkins))

## [v1.7.0-alpha1](https://github.com/platformsh/platformsh-cli/tree/v1.7.0-alpha1) (2015-01-15)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v1.6.3...v1.7.0-alpha1)

**Closed issues:**

- RFC: What to do about `platform list`? [\#122](https://github.com/platformsh/platformsh-cli/issues/122)

**Merged pull requests:**

- Archive and re-use old builds if the app's files have not changed [\#193](https://github.com/platformsh/platformsh-cli/pull/193) ([pjcdawkins](https://github.com/pjcdawkins))

## [v1.6.3](https://github.com/platformsh/platformsh-cli/tree/v1.6.3) (2015-01-12)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v1.6.2...v1.6.3)

**Closed issues:**

- Bug: branches have underscores stripped out [\#207](https://github.com/platformsh/platformsh-cli/issues/207)

**Merged pull requests:**

- Add an environment argument to many commands [\#210](https://github.com/platformsh/platformsh-cli/pull/210) ([pjcdawkins](https://github.com/pjcdawkins))
- Always create the \_local alias. [\#209](https://github.com/platformsh/platformsh-cli/pull/209) ([pjcdawkins](https://github.com/pjcdawkins))
- Ability to modify access on environment. [\#203](https://github.com/platformsh/platformsh-cli/pull/203) ([kotnik](https://github.com/kotnik))

## [v1.6.2](https://github.com/platformsh/platformsh-cli/tree/v1.6.2) (2015-01-07)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v1.6.1...v1.6.2)

**Merged pull requests:**

- Allow for invalid IDs in 'checkout' command. [\#208](https://github.com/platformsh/platformsh-cli/pull/208) ([pjcdawkins](https://github.com/pjcdawkins))
- Back up old Drush alias files [\#206](https://github.com/platformsh/platformsh-cli/pull/206) ([pjcdawkins](https://github.com/pjcdawkins))
- Use HOME system variable inside double quoted PATH export [\#205](https://github.com/platformsh/platformsh-cli/pull/205) ([steveoliver](https://github.com/steveoliver))
- Use a 'slugify' library to sanitize environment IDs \(like the UI does\) [\#200](https://github.com/platformsh/platformsh-cli/pull/200) ([pjcdawkins](https://github.com/pjcdawkins))

## [v1.6.1](https://github.com/platformsh/platformsh-cli/tree/v1.6.1) (2014-12-13)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v1.6.0...v1.6.1)

**Closed issues:**

- Don't allow deleting environment which have children [\#197](https://github.com/platformsh/platformsh-cli/issues/197)
- Search the documentation  [\#41](https://github.com/platformsh/platformsh-cli/issues/41)

**Merged pull requests:**

- Process special file destinations [\#199](https://github.com/platformsh/platformsh-cli/pull/199) ([pjcdawkins](https://github.com/pjcdawkins))

## [v1.6.0](https://github.com/platformsh/platformsh-cli/tree/v1.6.0) (2014-12-10)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v1.5.4...v1.6.0)

**Merged pull requests:**

- Add --merged option to deactivate all merged environments [\#196](https://github.com/platformsh/platformsh-cli/pull/196) ([pjcdawkins](https://github.com/pjcdawkins))
- Allow 'platform get' with no arguments [\#195](https://github.com/platformsh/platformsh-cli/pull/195) ([pjcdawkins](https://github.com/pjcdawkins))
- Update Drush aliases after getEnvironment\(\) [\#194](https://github.com/platformsh/platformsh-cli/pull/194) ([pjcdawkins](https://github.com/pjcdawkins))
- Add 'web' command [\#192](https://github.com/platformsh/platformsh-cli/pull/192) ([pjcdawkins](https://github.com/pjcdawkins))
- Add 'docs' command [\#182](https://github.com/platformsh/platformsh-cli/pull/182) ([pjcdawkins](https://github.com/pjcdawkins))

## [v1.5.4](https://github.com/platformsh/platformsh-cli/tree/v1.5.4) (2014-12-03)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v1.5.3...v1.5.4)

**Merged pull requests:**

- Prevent using 'platform get' inside a project directory. [\#191](https://github.com/platformsh/platformsh-cli/pull/191) ([pjcdawkins](https://github.com/pjcdawkins))

## [v1.5.3](https://github.com/platformsh/platformsh-cli/tree/v1.5.3) (2014-12-02)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v1.5.2...v1.5.3)

**Merged pull requests:**

- Clarify variable:set and variable:delete rebuild warning [\#190](https://github.com/platformsh/platformsh-cli/pull/190) ([pjcdawkins](https://github.com/pjcdawkins))
- Allow Drush and SSH to accept remote commands as arguments. [\#189](https://github.com/platformsh/platformsh-cli/pull/189) ([pjcdawkins](https://github.com/pjcdawkins))

## [v1.5.2](https://github.com/platformsh/platformsh-cli/tree/v1.5.2) (2014-11-28)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v1.5.1...v1.5.2)

**Closed issues:**

- environment:branch undefined index endpoint [\#188](https://github.com/platformsh/platformsh-cli/issues/188)

## [v1.5.1](https://github.com/platformsh/platformsh-cli/tree/v1.5.1) (2014-11-28)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v1.5.0...v1.5.1)

**Closed issues:**

- Errors running ssh-keys command [\#187](https://github.com/platformsh/platformsh-cli/issues/187)

## [v1.5.0](https://github.com/platformsh/platformsh-cli/tree/v1.5.0) (2014-11-28)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v1.4.5...v1.5.0)

**Merged pull requests:**

- Reformat the command list [\#184](https://github.com/platformsh/platformsh-cli/pull/184) ([pjcdawkins](https://github.com/pjcdawkins))

## [v1.4.5](https://github.com/platformsh/platformsh-cli/tree/v1.4.5) (2014-11-28)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v1.4.4...v1.4.5)

**Closed issues:**

- the project:init command no longer show with platform list [\#186](https://github.com/platformsh/platformsh-cli/issues/186)
- platform drush sql-dump error [\#185](https://github.com/platformsh/platformsh-cli/issues/185)
- When using a custom settings.php, settings.local.php is not included properly [\#175](https://github.com/platformsh/platformsh-cli/issues/175)

**Merged pull requests:**

- Project initialize command. [\#155](https://github.com/platformsh/platformsh-cli/pull/155) ([pjcdawkins](https://github.com/pjcdawkins))

## [v1.4.4](https://github.com/platformsh/platformsh-cli/tree/v1.4.4) (2014-11-26)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v1.4.3...v1.4.4)

**Merged pull requests:**

- Warn the user that the CLI does not run build/deploy hooks [\#178](https://github.com/platformsh/platformsh-cli/pull/178) ([pjcdawkins](https://github.com/pjcdawkins))
- Don't symlink settings.php; copy and warn. [\#176](https://github.com/platformsh/platformsh-cli/pull/176) ([pjcdawkins](https://github.com/pjcdawkins))
- Refactor Git/shell/Drush commands; and let toolstacks write output [\#167](https://github.com/platformsh/platformsh-cli/pull/167) ([pjcdawkins](https://github.com/pjcdawkins))

## [v1.4.3](https://github.com/platformsh/platformsh-cli/tree/v1.4.3) (2014-11-24)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v1.4.2...v1.4.3)

**Closed issues:**

- Add warning/notice logging to toolstacks [\#173](https://github.com/platformsh/platformsh-cli/issues/173)
- something broke my platform-cli [\#171](https://github.com/platformsh/platformsh-cli/issues/171)
- Add commands to get/set environment variables [\#131](https://github.com/platformsh/platformsh-cli/issues/131)
- Why would these values be NULL? [\#42](https://github.com/platformsh/platformsh-cli/issues/42)

**Merged pull requests:**

- Validate the SSH public key file [\#174](https://github.com/platformsh/platformsh-cli/pull/174) ([pjcdawkins](https://github.com/pjcdawkins))
- Remove .bat file. [\#169](https://github.com/platformsh/platformsh-cli/pull/169) ([pjcdawkins](https://github.com/pjcdawkins))

## [v1.4.2](https://github.com/platformsh/platformsh-cli/tree/v1.4.2) (2014-11-20)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v1.4.1...v1.4.2)

## [v1.4.1](https://github.com/platformsh/platformsh-cli/tree/v1.4.1) (2014-11-20)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v1.4.0...v1.4.1)

## [v1.4.0](https://github.com/platformsh/platformsh-cli/tree/v1.4.0) (2014-11-20)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v1.3.1...v1.4.0)

**Merged pull requests:**

- Provide --no-cache option forwarded to drush make for Drupal builds. [\#168](https://github.com/platformsh/platformsh-cli/pull/168) ([pjcdawkins](https://github.com/pjcdawkins))
- Windows instructions for adding Composer's vendor/bin to the PATH [\#165](https://github.com/platformsh/platformsh-cli/pull/165) ([pjcdawkins](https://github.com/pjcdawkins))
- Add variable get/set/delete commands. [\#161](https://github.com/platformsh/platformsh-cli/pull/161) ([pjcdawkins](https://github.com/pjcdawkins))

## [v1.3.1](https://github.com/platformsh/platformsh-cli/tree/v1.3.1) (2014-11-18)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v1.3.0...v1.3.1)

**Closed issues:**

- Composer\Repository\RepositorySecurityException [\#162](https://github.com/platformsh/platformsh-cli/issues/162)
- Error message after platform get: Drush must be installed [\#160](https://github.com/platformsh/platformsh-cli/issues/160)

**Merged pull requests:**

- Improve projects/environments refresh experience. [\#166](https://github.com/platformsh/platformsh-cli/pull/166) ([pjcdawkins](https://github.com/pjcdawkins))
- Auto-complete path arguments [\#164](https://github.com/platformsh/platformsh-cli/pull/164) ([pjcdawkins](https://github.com/pjcdawkins))

## [v1.3.0](https://github.com/platformsh/platformsh-cli/tree/v1.3.0) (2014-11-16)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v1.2.6...v1.3.0)

**Merged pull requests:**

- Support auto-completion. [\#158](https://github.com/platformsh/platformsh-cli/pull/158) ([pjcdawkins](https://github.com/pjcdawkins))
- Refactor filesystem, Drush, shell utilities [\#157](https://github.com/platformsh/platformsh-cli/pull/157) ([pjcdawkins](https://github.com/pjcdawkins))
- Add --pipe option to ssh. [\#152](https://github.com/platformsh/platformsh-cli/pull/152) ([pjcdawkins](https://github.com/pjcdawkins))

## [v1.2.6](https://github.com/platformsh/platformsh-cli/tree/v1.2.6) (2014-11-11)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v1.2.5...v1.2.6)

**Closed issues:**

- "Warning: max\(\): Array must contain at least one element" during "platform checkout" [\#153](https://github.com/platformsh/platformsh-cli/issues/153)
- Improve error output from Platform API call exceptions [\#150](https://github.com/platformsh/platformsh-cli/issues/150)
- Build command should give useful output [\#90](https://github.com/platformsh/platformsh-cli/issues/90)

## [v1.2.5](https://github.com/platformsh/platformsh-cli/tree/v1.2.5) (2014-11-09)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v1.2.4...v1.2.5)

**Merged pull requests:**

- Improve symlinks on Windows [\#156](https://github.com/platformsh/platformsh-cli/pull/156) ([pjcdawkins](https://github.com/pjcdawkins))
- Fix 'Array must contain at least one element' in checkout. [\#154](https://github.com/platformsh/platformsh-cli/pull/154) ([pjcdawkins](https://github.com/pjcdawkins))

## [v1.2.4](https://github.com/platformsh/platformsh-cli/tree/v1.2.4) (2014-11-06)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v1.2.3...v1.2.4)

**Closed issues:**

- Drush 'cannot move build into place' \(with --working-copy\) [\#145](https://github.com/platformsh/platformsh-cli/issues/145)

**Merged pull requests:**

- Improve error output from Platform.sh API calls. [\#151](https://github.com/platformsh/platformsh-cli/pull/151) ([pjcdawkins](https://github.com/pjcdawkins))

## [v1.2.3](https://github.com/platformsh/platformsh-cli/tree/v1.2.3) (2014-11-03)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v1.2.2...v1.2.3)

**Closed issues:**

- Have each command report on all of its properties [\#95](https://github.com/platformsh/platformsh-cli/issues/95)

## [v1.2.2](https://github.com/platformsh/platformsh-cli/tree/v1.2.2) (2014-10-29)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v1.2.1...v1.2.2)

## [v1.2.1](https://github.com/platformsh/platformsh-cli/tree/v1.2.1) (2014-10-29)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v1.2.0...v1.2.1)

**Closed issues:**

- UX - the 'platform get' command should fail if Git authentication fails [\#148](https://github.com/platformsh/platformsh-cli/issues/148)
- Make toolset modular and switch on the .platform.app.yaml file [\#146](https://github.com/platformsh/platformsh-cli/issues/146)
- Build fails for vanilla Drupal sites [\#142](https://github.com/platformsh/platformsh-cli/issues/142)
- The generated .gitignore file is always for Drupal [\#132](https://github.com/platformsh/platformsh-cli/issues/132)
- When I run environment:delete I'm borked [\#115](https://github.com/platformsh/platformsh-cli/issues/115)
- ProjectGetCommand.php contains Drupal specific code, needs to be abstracted as well [\#81](https://github.com/platformsh/platformsh-cli/issues/81)
- Drush command should only be present for Drupal projects [\#73](https://github.com/platformsh/platformsh-cli/issues/73)

## [v1.2.0](https://github.com/platformsh/platformsh-cli/tree/v1.2.0) (2014-10-28)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v1.1.0...v1.2.0)

**Fixed bugs:**

- I'm asked to log in twice [\#127](https://github.com/platformsh/platformsh-cli/issues/127)
- Remove project:delete [\#126](https://github.com/platformsh/platformsh-cli/issues/126)
- Remove override of doRun\(\) so the help option works. [\#138](https://github.com/platformsh/platformsh-cli/pull/138) ([pjcdawkins](https://github.com/pjcdawkins))
- project:clean should be local [\#128](https://github.com/platformsh/platformsh-cli/pull/128) ([pjcdawkins](https://github.com/pjcdawkins))

**Closed issues:**

- Commands should have a confirm step [\#141](https://github.com/platformsh/platformsh-cli/issues/141)
- Use a symlink for sites/default/files [\#140](https://github.com/platformsh/platformsh-cli/issues/140)
- Using --help with arguments doesn't help but launch command [\#137](https://github.com/platformsh/platformsh-cli/issues/137)
- error :   The "pipe" option does not exist. [\#133](https://github.com/platformsh/platformsh-cli/issues/133)
- Support spaces in directory/file names [\#124](https://github.com/platformsh/platformsh-cli/issues/124)
- platform  project:fix-aliases has an error [\#119](https://github.com/platformsh/platformsh-cli/issues/119)
- Login seems to fail through cli with very long password. [\#108](https://github.com/platformsh/platformsh-cli/issues/108)
- platform login command should probably be exposed [\#107](https://github.com/platformsh/platformsh-cli/issues/107)
- when not logged in platform list produces an error [\#106](https://github.com/platformsh/platformsh-cli/issues/106)
- The CLI assumes that the Git remote for Platform is named 'origin' [\#100](https://github.com/platformsh/platformsh-cli/issues/100)
- Build for symfony fails after get [\#98](https://github.com/platformsh/platformsh-cli/issues/98)
- Update readme on github [\#94](https://github.com/platformsh/platformsh-cli/issues/94)
- Rename regroup and clean commands [\#88](https://github.com/platformsh/platformsh-cli/issues/88)
- SSH command shold not depend on Drush [\#83](https://github.com/platformsh/platformsh-cli/issues/83)
- Improve drush aliases naming [\#59](https://github.com/platformsh/platformsh-cli/issues/59)
- Handle ``shell\_exec`` errors [\#57](https://github.com/platformsh/platformsh-cli/issues/57)
- Add support for synchronizing files and database [\#43](https://github.com/platformsh/platformsh-cli/issues/43)

**Merged pull requests:**

- Refactor local build process [\#147](https://github.com/platformsh/platformsh-cli/pull/147) ([pjcdawkins](https://github.com/pjcdawkins))
- Add confirm steps [\#144](https://github.com/platformsh/platformsh-cli/pull/144) ([pjcdawkins](https://github.com/pjcdawkins))
- Hide URLS by default in environments list \(width issue\). [\#143](https://github.com/platformsh/platformsh-cli/pull/143) ([pjcdawkins](https://github.com/pjcdawkins))
- Cache environments more often [\#139](https://github.com/platformsh/platformsh-cli/pull/139) ([pjcdawkins](https://github.com/pjcdawkins))
- Improve checkout command UX, and some extras [\#135](https://github.com/platformsh/platformsh-cli/pull/135) ([pjcdawkins](https://github.com/pjcdawkins))
- Update README.md [\#134](https://github.com/platformsh/platformsh-cli/pull/134) ([pjcdawkins](https://github.com/pjcdawkins))
- Provide a more stable 'branch' command. [\#130](https://github.com/platformsh/platformsh-cli/pull/130) ([pjcdawkins](https://github.com/pjcdawkins))
- Escape directory names in shell commands. [\#125](https://github.com/platformsh/platformsh-cli/pull/125) ([pjcdawkins](https://github.com/pjcdawkins))
- Add --yes and --no options, replacing --no-interaction. [\#123](https://github.com/platformsh/platformsh-cli/pull/123) ([pjcdawkins](https://github.com/pjcdawkins))
- Remove createDrushAliases\(\) from getDomains\(\). [\#118](https://github.com/platformsh/platformsh-cli/pull/118) ([pjcdawkins](https://github.com/pjcdawkins))
- Detect the current environment without using the upstream. [\#117](https://github.com/platformsh/platformsh-cli/pull/117) ([pjcdawkins](https://github.com/pjcdawkins))
- Use a stable version of symfony/finder. [\#114](https://github.com/platformsh/platformsh-cli/pull/114) ([pjcdawkins](https://github.com/pjcdawkins))
- App plugin builds toolstacks and tunnels [\#111](https://github.com/platformsh/platformsh-cli/pull/111) ([Kazanir](https://github.com/Kazanir))
- Fix synchronize command so that it accepts an argument. [\#105](https://github.com/platformsh/platformsh-cli/pull/105) ([pjcdawkins](https://github.com/pjcdawkins))
- Add a --pipe option to 'platform environments'. [\#104](https://github.com/platformsh/platformsh-cli/pull/104) ([pjcdawkins](https://github.com/pjcdawkins))
- Remove the infinite loop in 'login' if there is no STDIN. [\#102](https://github.com/platformsh/platformsh-cli/pull/102) ([pjcdawkins](https://github.com/pjcdawkins))
- Don't attempt 'cd /repository' after branching \(when outside project root\) [\#101](https://github.com/platformsh/platformsh-cli/pull/101) ([pjcdawkins](https://github.com/pjcdawkins))
- Fix missing concatenation operator for $message. [\#97](https://github.com/platformsh/platformsh-cli/pull/97) ([pjcdawkins](https://github.com/pjcdawkins))
- Add 'platform url' command to get the URL and open it in a browser. [\#96](https://github.com/platformsh/platformsh-cli/pull/96) ([pjcdawkins](https://github.com/pjcdawkins))
- Fix command description for environment:relationships. [\#92](https://github.com/platformsh/platformsh-cli/pull/92) ([pjcdawkins](https://github.com/pjcdawkins))
- Allow specifying a Drush alias group name \(and other minor drush alias features\) [\#89](https://github.com/platformsh/platformsh-cli/pull/89) ([pjcdawkins](https://github.com/pjcdawkins))

## [v1.1.0](https://github.com/platformsh/platformsh-cli/tree/v1.1.0) (2014-09-08)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v1.0.3...v1.1.0)

**Closed issues:**

- When `platform get` fails a directory should not be created [\#75](https://github.com/platformsh/platformsh-cli/issues/75)
- Can not clean old builds [\#65](https://github.com/platformsh/platformsh-cli/issues/65)
- New command to manage domains [\#63](https://github.com/platformsh/platformsh-cli/issues/63)
- Give platform build a --working-copy option [\#52](https://github.com/platformsh/platformsh-cli/issues/52)
- New command to upload SSL certificate [\#50](https://github.com/platformsh/platformsh-cli/issues/50)
- For discussion: Should CLI need a connection to Platform.sh to build locally? [\#49](https://github.com/platformsh/platformsh-cli/issues/49)
- Update CLI to handle environment:delete [\#48](https://github.com/platformsh/platformsh-cli/issues/48)
- Create a Shared/files directory that gets symlinked into the local build [\#46](https://github.com/platformsh/platformsh-cli/issues/46)
- How to specify a key other than .ssh/id\_rsa.pub? [\#44](https://github.com/platformsh/platformsh-cli/issues/44)
- Include .gitignore in itself by default [\#40](https://github.com/platformsh/platformsh-cli/issues/40)
- Add platform list output to .md file [\#37](https://github.com/platformsh/platformsh-cli/issues/37)
- New command: platform ssh [\#36](https://github.com/platformsh/platformsh-cli/issues/36)
- Installation via composer fails [\#34](https://github.com/platformsh/platformsh-cli/issues/34)
- How to do this with the CLI? \(@platform.local alias\) [\#33](https://github.com/platformsh/platformsh-cli/issues/33)
- Provide visibility into $\_ENV\['PLATFORM\_RELATIONSHIPS'\] [\#32](https://github.com/platformsh/platformsh-cli/issues/32)
- Make www symlink relative to project root [\#29](https://github.com/platformsh/platformsh-cli/issues/29)
- Add logout feature [\#23](https://github.com/platformsh/platformsh-cli/issues/23)
- Don't create folder if can't clone from repo [\#18](https://github.com/platformsh/platformsh-cli/issues/18)
- Platform compatability [\#12](https://github.com/platformsh/platformsh-cli/issues/12)

**Merged pull requests:**

- Add a \_local alias for the local Drupal build [\#80](https://github.com/platformsh/platformsh-cli/pull/80) ([Kazanir](https://github.com/Kazanir))
- Instantiate empty repo with project get [\#79](https://github.com/platformsh/platformsh-cli/pull/79) ([Kazanir](https://github.com/Kazanir))
- First, second, and third pass at SSL uploads. [\#78](https://github.com/platformsh/platformsh-cli/pull/78) ([Kazanir](https://github.com/Kazanir))
- Update WelcomeCommand.php [\#77](https://github.com/platformsh/platformsh-cli/pull/77) ([robertDouglass](https://github.com/robertDouglass))
- Refactored Application and huge use blocks, replaced custom ArgvInput [\#72](https://github.com/platformsh/platformsh-cli/pull/72) ([Kazanir](https://github.com/Kazanir))
- 36 add platform ssh passthru [\#71](https://github.com/platformsh/platformsh-cli/pull/71) ([Kazanir](https://github.com/Kazanir))
- Skip login requirements and add logout command [\#70](https://github.com/platformsh/platformsh-cli/pull/70) ([Kazanir](https://github.com/Kazanir))
- Add command to force rebuild of drush aliases [\#69](https://github.com/platformsh/platformsh-cli/pull/69) ([nvahalik](https://github.com/nvahalik))
- Command to remove old builds. [\#68](https://github.com/platformsh/platformsh-cli/pull/68) ([kotnik](https://github.com/kotnik))
- Make build links relative. [\#67](https://github.com/platformsh/platformsh-cli/pull/67) ([kotnik](https://github.com/kotnik))
- Don't skip SSL check. [\#66](https://github.com/platformsh/platformsh-cli/pull/66) ([GuGuss](https://github.com/GuGuss))
- Updated readme with updated install instructions regarding Drush. [\#64](https://github.com/platformsh/platformsh-cli/pull/64) ([nvahalik](https://github.com/nvahalik))
- Additional command to list the domains attached to a project. [\#62](https://github.com/platformsh/platformsh-cli/pull/62) ([GuGuss](https://github.com/GuGuss))
- Skip inactive environments while getting the project. [\#61](https://github.com/platformsh/platformsh-cli/pull/61) ([kotnik](https://github.com/kotnik))

## [v1.0.3](https://github.com/platformsh/platformsh-cli/tree/v1.0.3) (2014-08-08)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v1.0.2...v1.0.3)

**Closed issues:**

- Generated drush aliases are missing the "root" parameter [\#54](https://github.com/platformsh/platformsh-cli/issues/54)
- Autoloader can no longer find symfony-cli classes after a composer global update [\#53](https://github.com/platformsh/platformsh-cli/issues/53)

**Merged pull requests:**

- Update composer.json [\#56](https://github.com/platformsh/platformsh-cli/pull/56) ([bojanz](https://github.com/bojanz))

## [v1.0.2](https://github.com/platformsh/platformsh-cli/tree/v1.0.2) (2014-08-03)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v1.0.1...v1.0.2)

**Closed issues:**

- Add options to properly support running environment:synchronize with the --no-interaction option. [\#45](https://github.com/platformsh/platformsh-cli/issues/45)

## [v1.0.1](https://github.com/platformsh/platformsh-cli/tree/v1.0.1) (2014-07-21)
[Full Changelog](https://github.com/platformsh/platformsh-cli/compare/v1.0.0...v1.0.1)

**Closed issues:**

- Drush aliases are not working [\#35](https://github.com/platformsh/platformsh-cli/issues/35)

**Merged pull requests:**

- New argument to specify what to synchronize. [\#51](https://github.com/platformsh/platformsh-cli/pull/51) ([floretan](https://github.com/floretan))
- Don't create Drush alias for inactive environment. [\#47](https://github.com/platformsh/platformsh-cli/pull/47) ([kotnik](https://github.com/kotnik))

## [v1.0.0](https://github.com/platformsh/platformsh-cli/tree/v1.0.0) (2014-05-20)
**Closed issues:**

- Error when installing via composer [\#31](https://github.com/platformsh/platformsh-cli/issues/31)
- If there is only one branch, don't ask [\#30](https://github.com/platformsh/platformsh-cli/issues/30)
- Drush version requirements too strict [\#27](https://github.com/platformsh/platformsh-cli/issues/27)
- Unable to run platform directly on Windows [\#25](https://github.com/platformsh/platformsh-cli/issues/25)
- Cleanup after failed get, or don't fail if directory exists [\#24](https://github.com/platformsh/platformsh-cli/issues/24)
- Errors when getting an empty repo - i.e. a new project [\#19](https://github.com/platformsh/platformsh-cli/issues/19)
- Make sure docs are copy pastable and do not break users env [\#13](https://github.com/platformsh/platformsh-cli/issues/13)
- checkRequirements\(\) fails on Windows [\#11](https://github.com/platformsh/platformsh-cli/issues/11)
- Home Directory cannot be found on Windows [\#10](https://github.com/platformsh/platformsh-cli/issues/10)
- Naming of commands [\#5](https://github.com/platformsh/platformsh-cli/issues/5)

**Merged pull requests:**

- Fixing a number of issues around failed builds [\#28](https://github.com/platformsh/platformsh-cli/pull/28) ([dwkitchen](https://github.com/dwkitchen))
- Adding platform.bat file required for windows. Fixes \#25 [\#26](https://github.com/platformsh/platformsh-cli/pull/26) ([dwkitchen](https://github.com/dwkitchen))
- Make dependency on drush optional [\#22](https://github.com/platformsh/platformsh-cli/pull/22) ([fabpot](https://github.com/fabpot))
- fixed autoloading when installed globally via Composer [\#21](https://github.com/platformsh/platformsh-cli/pull/21) ([fabpot](https://github.com/fabpot))
- Fix installation via Composer [\#20](https://github.com/platformsh/platformsh-cli/pull/20) ([fabpot](https://github.com/fabpot))
- updating Drush version to account for lowercase as well [\#17](https://github.com/platformsh/platformsh-cli/pull/17) ([dudenhofer](https://github.com/dudenhofer))
- CLI is an abbreviation, so all caps. [\#16](https://github.com/platformsh/platformsh-cli/pull/16) ([kotnik](https://github.com/kotnik))
- Update guzzle in the lock file too. [\#15](https://github.com/platformsh/platformsh-cli/pull/15) ([kotnik](https://github.com/kotnik))
- Prettify help [\#8](https://github.com/platformsh/platformsh-cli/pull/8) ([damz](https://github.com/damz))
- Fix line breaks in login. [\#7](https://github.com/platformsh/platformsh-cli/pull/7) ([damz](https://github.com/damz))
- Switch to PSR-4, create a stub of a Application object, add shell [\#4](https://github.com/platformsh/platformsh-cli/pull/4) ([damz](https://github.com/damz))
- Fix displaying projects with the same name. [\#3](https://github.com/platformsh/platformsh-cli/pull/3) ([kotnik](https://github.com/kotnik))
- Composer lock and ignoring vendors [\#2](https://github.com/platformsh/platformsh-cli/pull/2) ([kotnik](https://github.com/kotnik))
- Small fixes [\#1](https://github.com/platformsh/platformsh-cli/pull/1) ([kotnik](https://github.com/kotnik))
