IDE_NAME?=openapi-generator-server
PHP_VERSION?=7.4-cli
ROOT?=$(PWD)
PROJECT_ROOT?=$(abspath ../../..)

build-image:
	docker build --tag openapi-generator:latest --build-arg PHP_VERSION=$(PHP_VERSION) .

test:
	@if [ -f "$(PROJECT_ROOT)/vendor/autoload.php" ] && [ -x "$(PROJECT_ROOT)/vendor/bin/phpunit" ]; then \
		docker run --rm --user="$(shell id -u):$(shell id -g)" --volume "$(PROJECT_ROOT):/var/www/html" \
			--workdir /var/www/html/vendor/wapmorgan/openapi-generator composer:2.8.10 \
			php /var/www/html/vendor/bin/phpunit --bootstrap /var/www/html/vendor/autoload.php tests; \
	else \
		docker run --rm --user="$(shell id -u):$(shell id -g)" --volume "$(ROOT):/app" --workdir /app \
			composer:2.8.10 sh -lc 'composer install --no-interaction --prefer-dist && composer test'; \
	fi

install-openapi-generator:
	docker run --rm --interactive --tty --user="$(id -u):$(id -g)" --volume /tmp/:/tmp/cache --volume $PWD:/app \
		composer:2.2.4 \
		composer require --prefer-source --dev --ignore-platform-req php --ignore-platform-req ext-intl \
		"wapmorgan/openapi-generator"

test-xdebug:
	docker run --interactive --rm --tty --user="$(id -u):$(id -g)" --volume $(ROOT):/app \
		--workdir /app --add-host host.docker.internal:host-gateway \
		--env "XDEBUG_CONFIG=remote_autostart=1 client_host=host.docker.internal log=/dev/stdout" \
		--env "PHP_IDE_CONFIG=serverName=$(IDE_NAME)" \
		--env "XDEBUG_TRIGGER=1" \
		openapi-generator:latest php --ri xdebug

run:
	docker run --interactive --rm --tty --user="$(id -u):$(id -g)" --volume $(ROOT):/app \
		--workdir /app --add-host host.docker.internal:host-gateway \
		--env "XDEBUG_CONFIG=remote_autostart=1 client_host=host.docker.internal log=/dev/stdout" \
		--env "PHP_IDE_CONFIG=serverName=$(IDE_NAME)" \
		--env "XDEBUG_TRIGGER=1" \
		openapi-generator:latest ./vendor/bin/openapi-generator $(ARGS)
