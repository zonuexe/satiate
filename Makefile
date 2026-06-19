.PHONY: all install test lint cs cs-fix infection dogfood clean build

all: build

install:
	composer install
	composer bin phpstan install
	composer bin easycs install
	composer bin phpunit install
	composer bin infection install

test:
	composer phpunit

lint:
	composer phpstan analyse src/ tests/

cs:
	.vendor-bin/easycs/vendor/symplify/easy-coding-standard/bin/ecs check src/ tests/ bin/

cs-fix:
	.vendor-bin/easycs/vendor/symplify/easy-coding-standard/bin/ecs check src/ tests/ bin/ --fix

# Mutation testing. Needs a coverage driver (PCOV or Xdebug); kept out of `build` as it is slow.
infection:
	./bin/infection --threads=max

dogfood:
	./bin/dogfood-test

build: lint cs test dogfood

clean:
	rm -rf output/
