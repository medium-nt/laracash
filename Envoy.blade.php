@setup
    $user = 'fh791290_laracash';
    $server = 'fh791290.beget.tech';
@endsetup

@servers(['web' => [$user.'@'.$server]])

@task('deploy', ['on' => 'web'])
    set -e
    echo "Deploying..."

    git pull origin main
    php8.3 artisan down

    php8.3 composer.phar install --no-dev --optimize-autoloader
    php8.3 artisan migrate --force

    php8.3 artisan config:cache
    php8.3 artisan route:cache
    php8.3 artisan view:cache

    php8.3 artisan up

    echo "Done!"
@endtask

@task('pull', ['on' => 'web'])
    set -e
    echo "Deploying..."
    git pull origin main
    echo "Done!"
@endtask

@task('migrate', ['on' => 'web'])
    set -e
    echo "Deploying..."
    php8.3 artisan migrate --force
    echo "Done!"
@endtask
