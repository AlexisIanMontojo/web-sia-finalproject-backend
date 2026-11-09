<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class ServiceCatalogModel extends Model
{
    protected $table = 'service_catalog';
    protected $primary_key = 'id';

    protected $fillable = [
        'name', 'description', 'category', 'price', 'unit',
        'requires_schedule', 'duration_minutes', 'capacity', 'is_active',
    ];
    protected $guarded = ['id'];

    public function allOrdered()
    {
        return $this->db->table($this->table)
            ->order_by('name', 'ASC')
            ->get_all();
    }

    public function active()
    {
        return $this->db->table($this->table)
            ->where('is_active', 1)
            ->order_by('name', 'ASC')
            ->get_all();
    }

    public function findActive($id)
    {
        $rows = $this->db->table($this->table)
            ->where('id', (int) $id)
            ->where('is_active', 1)
            ->get_all();

        return $rows[0] ?? null;
    }
}