<?php

class Create_reservation_payment_services
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->dbforge();
    }

    public function up()
    {
        if ($this->_lava->dbforge->table_exists('reservation_payment_services')) {
            return;
        }

        $this->_lava->dbforge
            ->add_field([
                'id' => [
                    'type'           => 'INT',
                    'constraint'     => 11,
                    'unsigned'       => TRUE,
                    'auto_increment' => TRUE,
                    'null'           => FALSE,
                ],
                'payment_id' => [
                    'type'       => 'INT',
                    'constraint' => 11,
                    'unsigned'   => TRUE,
                    'null'       => FALSE,
                ],
                'reservation_service_id' => [
                    'type'       => 'INT',
                    'constraint' => 11,
                    'unsigned'   => TRUE,
                    'null'       => FALSE,
                ],
                'amount' => [
                    'type'       => 'DECIMAL',
                    'constraint' => '10,2',
                    'null'       => FALSE,
                ],
                'created_at' => [
                    'type'    => 'DATETIME',
                    'null'    => FALSE,
                    'default' => 'CURRENT_TIMESTAMP',
                ],
            ])
            ->add_key('id', primary: TRUE)
            ->add_key('payment_id', name: 'reservation_payment_services_payment_idx')
            ->add_key('reservation_service_id', name: 'reservation_payment_services_service_idx')
            ->add_key(
                ['payment_id', 'reservation_service_id'],
                unique: TRUE,
                name: 'reservation_payment_services_unique'
            )
            ->create_table('reservation_payment_services');
    }

    public function down()
    {
        $this->_lava->dbforge->drop_table('reservation_payment_services');
    }
}
