exec:
	docker exec -ti whatwedo_sylius_datatrans_payment_plugin_web /bin/sh

install:
	COMPOSER_MEMORY_LIMIT=-1 composer install

ecs:
	@vendor/bin/ecs check src tests --clear-cache --config vendor/whatwedo/php-coding-standard/config/whatwedo-symfony.php --fix

phpstan:
	@./vendor/bin/phpstan analyse
