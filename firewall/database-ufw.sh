#!/bin/bash
# Firewall for the database VM

# default rules
sudo ufw default reject incoming
sudo ufw default allow outgoing

# allow ssh
sudo ufw allow 22/tcp

# reject mysql from outside
sudo ufw reject 3306/tcp

# turn on firewall
sudo ufw --force enable
sudo ufw status numbered
