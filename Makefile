IDE_NAME?=openapi-generator-server
PHP_VERSION?=7.4-cli
ROOT?=$(PWD)

build-image:
	docker build --tag openapi-generator:latest --build-arg PHP_VERSION=$(PHP_VERSION) .

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
