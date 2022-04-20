# Mondu for Magento 2

## Installation

### Install using Composer (Recommended)
<ol>
<li> From the CLI, run the following commands to install the Mondu module.
<code>composer require mondu/magento2-payment</code></li>
<li> Run Magento install/upgrade scripts: <code><em>php bin/magento setup:upgrade</em></code> </li>
<li> Compile dependency injection: <code><em>php bin/magento setup:di:compile</em></code> </li>
<li> Deploy static view files (production mode only): <code><em>php bin/magento setup:static-content:deploy</em></code> </li>
<li> Flush Magento cache: <code><em>php bin/magento cache:flush</em></code></li>
</ol>

### Install using Docker
<ol>
<li> Install docker and docker-compose.
<li> Run the following script: <code><em>docker-compose up</em></code> </li>
</ol>

### Install manually
<ol>
<li> Download the latest release of Mondu module for Magento 2 file from the Mondu github repository https://github.com/mondu-ai/magento2-checkout/releases </li>
<li> Unzip the file</li>
<li> Create a directory `Mondu/Mondu` in: <em>[MAGENTO]/app/code/ </em> </li>
<li> Copy the files to `Mondu/Mondu` directory </li>
<li> Run Magento install/upgrade scripts: <code><em>php bin/magento setup:upgrade</em></code> </li>
<li> Compile dependency injection: <code><em>php bin/magento setup:di:compile</em></code> </li>
<li> Deploy static view files (production mode only): <code><em>php bin/magento setup:static-content:deploy</em></code> </li>
<li> Flush Magento cache: <code><em>php bin/magento cache:flush</em></code></li>
</ol>
