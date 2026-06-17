.PHONY: all install test lint cs cs-fix clean build

all: build

install:
	composer install
	composer bin phpstan install
	composer bin easycs install
	composer bin phpunit install

test:
	composer phpunit

lint:
	composer phpstan analyse src/ tests/

cs:
	.vendor-bin/easycs/vendor/symplify/easy-coding-standard/bin/ecs check src/ tests/ bin/

cs-fix:
	.vendor-bin/easycs/vendor/symplify/easy-coding-standard/bin/ecs check src/ tests/ bin/ --fix

build: lint cs test

clean:
	rm -rf output/
