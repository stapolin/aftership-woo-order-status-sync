# AfterShip WooCommerce Order Status Sync

This plugin lets you map AfterShip tracking event tags to WooCommerce order statuses so shipment updates automatically adjust orders in WooCommerce. Configure it from the WooCommerce admin area and point your AfterShip webhook to the plugin’s REST endpoint.

## Features
- Map common AfterShip tags (e.g., InfoReceived, InTransit, Delivered) to WooCommerce order statuses of your choice.
- Optional webhook secret token validation via the `X-AfterShip-Token` header.
- Enable or disable event logging and download or clear the log from the settings page.
- Uses a REST webhook endpoint at `stapolin-aftership/v1/webhook` for AfterShip “Tracking Update” events.

## Setup
1. Upload the plugin file `aftership-woocommerce-sync.php` to your WordPress installation.
2. Activate the plugin in **Plugins → Installed Plugins**.
3. Go to **WooCommerce → AfterShip Sync** to configure:
   - Webhook secret token (optional but recommended).
   - Status mappings for each AfterShip tag.
   - Logging preferences and log management.
4. In AfterShip, set your webhook URL to `https://your-site.com/wp-json/stapolin-aftership/v1/webhook` and, if configured, add the `X-AfterShip-Token` header with your secret.

## Logging
Logs are written to `wp-content/uploads/stapolin-aftership-webhook.log` when logging is enabled. You can view the most recent entries, download the full log, or clear it from the plugin settings page.

## Requirements
- WordPress with WooCommerce installed and active.
- REST API accessible for webhooks.
