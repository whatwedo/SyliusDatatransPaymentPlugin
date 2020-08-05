exec:
	docker exec -ti whatwedo_sylius_datatrans_payment_plugin_web /bin/sh

install:
	COMPOSER_MEMORY_LIMIT=-1 composer install
