IDE_NAME?=openapi-generator-server

build-image:
	docker build --tag openapi-generator:latest .

test-xdebug:
	docker run --interactive --rm --tty --user="$(id -u):$(id -g)" --volume /home/wapmorgan/work/ag/cms-laravel:/app \
		--workdir /app --add-host host.docker.internal:host-gateway \
		--env "XDEBUG_CONFIG=remote_autostart=1 client_host=host.docker.internal log=/dev/stdout" \
		--env "PHP_IDE_CONFIG=serverName=$(IDE_NAME)" \
		--env "XDEBUG_TRIGGER=1" \
		openapi-generator:latest php --ri xdebug

run:
	docker run --interactive --rm --tty --user="$(id -u):$(id -g)" --volume /home/wapmorgan/work/ag/cms-laravel:/app \
		--workdir /app --add-host host.docker.internal:host-gateway \
		--env "XDEBUG_CONFIG=remote_autostart=1 client_host=host.docker.internal log=/dev/stdout" \
		--env "PHP_IDE_CONFIG=serverName=$(IDE_NAME)" \
		--env "XDEBUG_TRIGGER=1" \
		openapi-generator:latest ./vendor/bin/openapi-generator $(ARGS)
