@setup
    $deploy      = new Exolnet\Envoy\ConfigDeploy(get_defined_vars());
    $environment = $deploy->getEnvironment();
    extract($environment->extractVariables());
@endsetup

@servers(['web' => $serverString])

@task('releases:current')
    basename "$(realpath "{{ $currentPath }}")"
@endtask

@setup
    if ($current === true) {
        $environment->detectCurrentRelease();
        extract($environment->extractVariables());
    }
@endsetup

@task('assert:commit')
    @if (! $commit)
        echo "Commit not defined." 1>&2
        exit 1
    @else
        echo "Deploying release {{ $release }} with tree-ish {{ $commit }} to environment {{ $environment->getName() }}..."
    @endif
@endtask

@macro('backups')
    backups:list
@endmacro

@task('backups:list')
    ls -1 "{{ $backupsPath }}"
@endtask

@macro('deploy')
    assert:commit
    deploy:starting
        deploy:check
        deploy:backup
    deploy:started
    deploy:provisioning
        deploy:fetch
        deploy:release
        deploy:git
        deploy:link
        deploy:copy
        deploy:composer
        deploy:npm
    deploy:provisioned
    deploy:building
        deploy:build
    deploy:built
    deploy:publishing
        deploy:symlink
        deploy:publish
        deploy:cronjobs
    deploy:published
    deploy:finishing
        deploy:cleanup
    deploy:finished
@endmacro

@task('deploy:starting')
    true
@endtask

@task('deploy:check')
    if [ ! -d "{{ $repositoryPath }}" ]; then
        echo "Repository path not found." 1>&2
        exit 1
    fi

    if [ "$(git --git-dir {{ $repositoryPath }} rev-parse --is-bare-repository)" != "true" ]; then
        echo "Repository is not bare." 1>&2
        exit 1
    fi

    if [ ! -d "{{ $releasesPath }}" ]; then
        echo "Releases path not found." 1>&2
        exit 1
    fi

    if [ ! -d "{{ $sharedPath }}" ]; then
        echo "Shared path not found." 1>&2
        exit 1
    fi

    if [ ! -d "{{ $backupsPath }}" ]; then
        echo "Backups path not found." 1>&2
        exit 1
    fi

    echo "All checks passed!"
@endtask

@task('deploy:backup')
    true
@endtask

@task('deploy:started')
    true
@endtask

@task('deploy:provisioning')
    true
@endtask

@task('deploy:fetch')
    @if ($gitSshCommand)
        export GIT_SSH_COMMAND="{{ $gitSshCommand }}"
    @endif

    {{ $cmdGit }} --git-dir "{{ $repositoryPath }}" remote set-url origin "{{ $repositoryUrl }}"
    {{ $cmdGit }} --git-dir "{{ $repositoryPath }}" fetch origin +refs/heads/*:refs/heads/* +refs/tags/*:refs/tags/* --prune
@endtask

@task('deploy:release')
    mkdir "{{ $releasePath }}"
    cd "{{ $releasePath }}"

    {{ $cmdGit }} --git-dir "{{ $repositoryPath }}" --work-tree "{{ $releasePath }}" checkout -f {{ $commit }}
    {{ $cmdGit }} --git-dir "{{ $repositoryPath }}" --work-tree "{{ $releasePath }}" rev-parse HEAD > "{{ $releasePath }}/REVISION"
@endtask

@task('deploy:git')
    cd "{{ $releasePath }}"

    cat > "{{ $releasePath }}/git" <<'EOL'
    #!/usr/bin/env bash
    {{ $cmdGit }} --git-dir "{{ $repositoryPath }}" --work-tree "{{ $releasePath }}" "$@"
    EOL

    chmod +x "{{ $releasePath }}/git"
@endtask

@task('deploy:link')
    @run('deploy:link:dirs')
    @run('deploy:link:files')
@endtask

@task('deploy:link:dirs')
    @foreach ($linkedDirs as $dir)
        echo "Linking directory {{ $releasePath }}/{{ $dir }} to {{ $sharedPath }}/{{ $dir }}"

        mkdir -p `dirname "{{ $sharedPath }}/{{ $dir }}"`

        if [ -d "{{ $releasePath }}/{{ $dir }}" ]; then
            if [ ! -d "{{ $sharedPath }}/{{ $dir }}" ]; then
                cp -r "{{ $releasePath }}/{{ $dir }}" "{{ $sharedPath }}/{{ $dir }}"
            fi

            rm -rf "{{ $releasePath }}/{{ $dir }}"
        fi

        if [ ! -d "{{ $sharedPath }}/{{ $dir }}" ]; then
            mkdir "{{ $sharedPath }}/{{ $dir }}"
        fi

        mkdir -p `dirname "{{ $releasePath }}/{{ $dir }}"`

        ln -srfn "{{ $sharedPath }}/{{ $dir }}" "{{ $releasePath }}/{{ $dir }}"
    @endforeach
@endtask

@task('deploy:link:files')
    @foreach ($linkedFiles as $file)
        echo "Linking file {{ $releasePath }}/{{ $file }} to {{ $sharedPath }}/{{ $file }}"

        mkdir -p `dirname "{{ $sharedPath }}/{{ $file }}"`

        if [ -f "{{ $releasePath }}/{{ $file }}" ]; then
            if [ ! -f "{{ $sharedPath }}/{{ $file }}" ]; then
                cp "{{ $releasePath }}/{{ $file }}" "{{ $sharedPath }}/{{ $file }}"
            fi

            rm -f "{{ $releasePath }}/{{ $file }}"
        fi

        if [ ! -f "{{ $sharedPath }}/{{ $file }}" ]; then
            touch "{{ $sharedPath }}/{{ $file }}"
        fi

        mkdir -p `dirname "{{ $releasePath }}/{{ $file }}"`

        ln -srfn "{{ $sharedPath }}/{{ $file }}" "{{ $releasePath }}/{{ $file }}"
    @endforeach
@endtask

@task('deploy:copy')
    @run('deploy:copy:dirs')
    @run('deploy:copy:files')
@endtask

@task('deploy:copy:dirs')
    @foreach ($copiedDirs as $dir)
        echo "Copying directory {{ $currentPath }}/{{ $dir }} to {{ $releasePath }}/{{ $dir }}"

        mkdir -p `dirname "{{ $releasePath }}/{{ $dir }}"`

        if [ -d "{{ $currentPath }}/{{ $dir }}" ]; then
            rsync -a "{{ $currentPath }}/{{ $dir ? rtrim($dir, '/') .'/' : '' }}" "{{ $releasePath }}/{{ $dir }}"
        fi
    @endforeach
@endtask

@task('deploy:copy:files')
    @foreach ($copiedFiles as $file)
        echo "Copying file {{ $currentPath }}/{{ $file }} to {{ $releasePath }}/{{ $file }}"

        mkdir -p `dirname "{{ $releasePath }}/{{ $file }}"`

        if [ -f "{{ $currentPath }}/{{ $file }}" ]; then
            rsync -a "{{ $currentPath }}/{{ $file }}" "{{ $releasePath }}/{{ $file }}"
        fi
    @endforeach
@endtask

@task('deploy:composer')
    @foreach (array_unique([$releasePath, $assetsPath]) as $path)
        cd "{{ $path }}"

        if [ -f "composer.json" ]; then
            {{ $cmdComposer }} install {{ $cmdComposerOptions }} --prefer-dist --optimize-autoloader --no-progress --no-interaction
        fi
    @endforeach
@endtask

@task('deploy:npm')
    cd "{{ $assetsPath }}"

    if [ -f "package.json" ]; then
        if [ -f "yarn.lock" ]; then
            {{ $cmdYarn }} install --pure-lockfile --no-progress --non-interactive
        else
            {{ $cmdNpm }} install
        fi
    fi
@endtask

@task('deploy:provisioned')
    true
@endtask

@task('deploy:building')
    true
@endtask

@task('deploy:build')
    cd "{{ $assetsPath }}"

    if [ -f "package.json" ]; then
        if [ -f "yarn.lock" ]; then
            {{ $cmdYarn }} run production
        else
            {{ $cmdNpm }} run production
        fi
    fi
@endtask

@task('deploy:built')
    true
@endtask

@task('deploy:publishing')
    true
@endtask

@task('deploy:symlink')
    echo "Linking directory {{ $releasePath }} to {{ $currentPath }}"

    ln -srfn "{{ $releasePath }}" "{{ $currentPath }}"
@endtask

@task('deploy:publish')
    true
@endtask

@task('deploy:cronjobs')
    FILE=$(mktemp)
    crontab -l > $FILE || true

    sed -i '/# EXOLNET-LARAVEL-ENVOY BEGIN {{ $fingerprint }}/,/# EXOLNET-LARAVEL-ENVOY END {{ $fingerprint }}/d' $FILE

    @if (is_array($cronJobs) && count($cronJobs) > 0)
        echo '# EXOLNET-LARAVEL-ENVOY BEGIN {{ $fingerprint }}' >> $FILE
        echo 'SHELL="/bin/bash"' >> $FILE
        echo 'MAILTO="{{ $cronMailTo }}"' >> $FILE
        @foreach ($cronJobs as $cronJob)
            echo {{ escapeshellarg($cronJob) }} >> $FILE
        @endforeach
        echo '# EXOLNET-LARAVEL-ENVOY END {{ $fingerprint }}' >> $FILE
    @endif

    if [ -s $FILE ]; then
        crontab $FILE
    else
        crontab -r || true
    fi

    rm $FILE
@endtask

@task('purge:cronjobs')
    FILE=$(mktemp)
    crontab -l > $FILE || true

    sed -i '/# EXOLNET-LARAVEL-ENVOY BEGIN {{ $fingerprint }}/,/# EXOLNET-LARAVEL-ENVOY END {{ $fingerprint }}/d' $FILE

    if [ -s $FILE ]; then
        crontab $FILE
    else
        crontab -r || true
    fi

    rm $FILE
@endtask

@task('deploy:published')
    true
@endtask

@task('deploy:finishing')
    true
@endtask

@task('deploy:cleanup')
    cd "{{ $releasesPath }}"

    for RELEASE in $(ls -1d * | head -n -{{ $keepReleases }}); do
        echo "Deleting old release $RELEASE"
        rm -rf "$RELEASE"
    done
@endtask

@task('deploy:finished')
    true
@endtask

@macro('releases')
    releases:list
@endmacro

@task('releases:list')
    ls -1 "{{ $releasesPath }}"
@endtask

@macro('rollback')
    deploy:starting
        deploy:check
        deploy:backup
    deploy:started
    deploy:publishing
        rollback:symlink
        deploy:publish
        deploy:cronjobs
    deploy:published
    deploy:finishing
    deploy:finished
@endmacro

@task('rollback:symlink')
    @if (isset($release))
        RELEASE="{{ $release }}"
    @else
        cd "{{ $releasesPath }}"
        RELEASE=`ls -1d */ | head -n -1 | tail -n 1 | sed "s/\/$//"`
    @endif

    if [ ! -d "{{ $releasesPath }}/$RELEASE" ]; then
        echo "Release $RELEASE not found. Could not rollback."
        exit 1
    fi

    echo "Linking directory {{ $releasesPath }}/$RELEASE to {{ $currentPath }}"

    ln -srfn "{{ $releasesPath }}/$RELEASE" "{{ $currentPath }}"
@endtask

@macro('setup')
    setup:repository
    setup:directories
@endmacro

@task('setup:repository')
    @if ($gitSshCommand)
        export GIT_SSH_COMMAND="{{ $gitSshCommand }}"
    @endif

    if [ -d "{{ $repoPath }}" ]; then
        echo "Deleting directory {{ $repoPath }}" 1>&2
        rm -rf "{{ $repoPath }}"
    fi

    if [ ! -d "{{ $repositoryPath }}" ]; then
        {{ $cmdGit }} clone --bare "{{ $repositoryUrl }}" "{{ $repositoryPath }}"
        {{ $cmdGit }} --git-dir "{{ $repositoryPath }}" config advice.detachedHead false
    fi
@endtask

@task('setup:directories')
    if [ ! -d "{{ $releasesPath }}" ]; then
        mkdir -v "{{ $releasesPath }}"
    fi

    if [ ! -d "{{ $sharedPath }}" ]; then
        mkdir -v "{{ $sharedPath }}"
    fi

    if [ ! -d "{{ $backupsPath }}" ]; then
        mkdir -v "{{ $backupsPath }}"
    fi
@endtask

@task('fetch:app_url')
    grep -e "^APP_URL" "{{ $releasePath }}/.env" | cut -d '=' -f 2- | tr -d '"' | tr -d "'"
@endtask

@error
    if ($task === 'assert:commit') {
        throw new Exception("No tree-ish specified to deploy. Please provide one using '--commit=tree-ish'.");
    } elseif ($task === 'deploy:check') {
        throw new Exception("Unmet prerequisites to deploy. Have you run 'setup' ?");
    } else {
        throw new Exception('Whoops, looks like something went wrong.');
    }
@enderror

@finished
    if (Illuminate\Support\Str::startsWith($task, ['deploy', 'rollback'])) {
        $deploy->detectSlack();

        if ($deploy->has('slack_url')) {
            $environment->detectAppUrl();
            Exolnet\Envoy\Slack::make($environment, $deploy, $task)->send();
        }
    }
@endfinished
