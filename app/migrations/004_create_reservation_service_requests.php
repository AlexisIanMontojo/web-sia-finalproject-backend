<?php

class Create_reservation_service_requests
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->dbforge();
    }

    public function up()
    {
        if ($this->_lava->dbforge->table_exists('reservation_service_requests')) {
            return;
        }

        $this->_lava->dbforge
            ->add_field([
                'idempotency_key' => [
                    'type'       => 'CHAR',
                    'constraint' => 36,
                    'null'       => FALSE,
                ],
                'reservation_service_id' => [
                    'type'       => 'INT',
                    'constraint' => 11,
                    'unsigned'   => TRUE,
                    'null'       => FALSE,
                ],
                'created_at' => [
                    'type'    => 'DATETIME',
                    'null'    => FALSE,
                    'default' => 'CURRENT_TIMESTAMP',
                ],
            ])
            ->add_key('idempotency_key', primary: TRUE)
            ->add_key(
                'reservation_service_id',
                unique: TRUE,
                name: 'reservation_service_requests_service_unique'
            )
            ->create_table('reservation_service_requests');
    }

    public function down()
    {
        $this->_lava->dbforge->drop_table('reservation_service_requests');
    }
}
