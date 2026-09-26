-- Allow existing LAN records to use an existing RouterOS bridge interface.
ALTER TABLE network_lans
    MODIFY COLUMN network_type ENUM('LAN','VLAN','BRIDGE','PPPOE','HOTSPOT','OTHER') NOT NULL DEFAULT 'LAN';
