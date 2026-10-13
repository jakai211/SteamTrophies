#!/bin/bash
#firewall for the RabbitMQ VM

sudo ufw default reject incoming
sudo ufw default allow outgoing

sudo ufw allow 22/tcp

#allow rabbitmq only from the frontend and database VMs
sudo ufw allow from FRONTEND_IP to any port 5672 proto tcp
sudo ufw allow from DB_VM_IP to any port 5672 proto tcp

#reject rabbitmq from everyone else
sudo ufw reject 5672/tcp
sudo ufw reject 15672/tcp

#to turn on firewall
sudo ufw --force enable
sudo ufw status numbered
