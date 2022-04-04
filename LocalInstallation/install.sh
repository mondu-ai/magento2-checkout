PLUGIN_VERSION="1.0.2"
RELEASE_URL="https://github.com/mondu-ai/magento2-checkout/archive/refs/tags/$PLUGIN_VERSION.tar.gz"

if [ -z ${1+x} ]; then echo "please provide the version"; exit; else echo "using version '$1'"; fi

if [ $1 != 2210 ]
then
    echo "invalid version: valid versions are [ 2210 ]"
    exit 0
fi

mkdir -p ./magento2210

if [ -d "./magento2210" ]
then
    if [ "$(ls -A ./magento2210)" ]; then
        echo "magento2210 directory is not empty"
    else
        if [ -f "./Magento2210.tar.gz" ]; then
            tar -zxf ./Magento2210.tar.gz -C ./magento2210
        else
            echo "Magento2210.tar.gz does not exists"
            exit
        fi
    fi
else
    exit 0
fi

touch ./magento2210/docker-compose.yml
cp ./docker-compose_2210.yml ./magento2210/docker-compose.yml

touch ./dockerlog.txt
cd ./magento2210

mkdir -p ./app/code/Mondu
if [ -d "./app/code/Mondu" ]; then
    if [ "$(ls -A ./app/code/Mondu/Mondu)" ]; then
        echo "Mondu plugin already installed"
        exit 0
    else
        wget -c "$RELEASE_URL" -O - | tar -xz
        mv "magento2-checkout-$PLUGIN_VERSION" ./app/code/Mondu/Mondu
    fi
fi

search='https:\/\/api.mondu.ai\/api\/v1'
replace='https:\/\/api.stage.mondu.ai\/api\/v1'

sed -i "s/$search/$replace/gi" "./app/code/Mondu/Mondu/Model/Ui/ConfigProvider.php"

search='https:\/\/checkout.mondu.ai\/widget.js'
replace='https:\/\/checkout.stage.mondu.ai\/widget.js'

sed -i "s/$search/$replace/gi" "./app/code/Mondu/Mondu/Model/Ui/ConfigProvider.php"

if docker-compose down &>> ../dockerlog.txt  ; then
    docker-compose down
    docker-compose up -d
    sleep 10
    docker exec -i magento_2210 bash -c 'cd app && php bin/magento setup:install --base-url="http://127.0.0.1:8080" --db-host="mysql" --db-name="magento" --db-user="root" --db-password="root" --admin-firstname="Tigran" --admin-lastname="Hovhannisyan" --admin-email="hello@example.co.uk" --admin-user="admin" --admin-password="admin123" --language="en_US" --currency="EUR" --timezone="Europe/London" --use-rewrites="1" --backend-frontname="admin"'
    docker exec -i magento_2210 bash -c 'cd app && php bin/magento setup:upgrade'
    docker exec -i magento_2210 bash -c 'chown -R application:application ./app'
else
    echo "running docker as sudo"
    sudo docker-compose down
    sudo docker-compose up -d
    sleep 10
    sudo docker exec -i magento_2210 bash -c 'cd app && php bin/magento setup:install --base-url="http://127.0.0.1:8080" --db-host="mysql" --db-name="magento" --db-user="root" --db-password="root" --admin-firstname="Tigran" --admin-lastname="Hovhannisyan" --admin-email="hello@example.co.uk" --admin-user="admin" --admin-password="admin123" --language="en_US" --currency="EUR" --timezone="Europe/London" --use-rewrites="1" --backend-frontname="admin"'
    sudo docker exec -i magento_2210 bash -c 'cd app && php bin/magento setup:upgrade'
    sudo docker exec -i magento_2210 bash -c 'chown -R application:application ./app'
fi
