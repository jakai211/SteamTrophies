#!/bin/bash
#firewall for the DMZ VM

sudo ufw default reject incoming
sudo ufw default allow outgoing

#allow ssh
sudo ufw allow 22/tcp

#allow website traffic
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp

#reject database and rabbitmq
sudo ufw reject 3306/tcp
sudo ufw reject 5672/tcp
sudo ufw reject 15672/tcp

#turns on firewall
sudo ufw --force enable
sudo ufw status numbered
