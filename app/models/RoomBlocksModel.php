<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class RoomBlocksModel extends Model
{
    protected $table = 'room_blocks';
    protected $primary_key = 'id';

    protected $fillable = ['room_id', 'start_date', 'end_date', 'block_type', 'reason', 'created_by'];
    protected $guarded = ['id'];

    /** Blocks that overlap the range. end_date is the first night the room is open again. */
    public function overlapping($from, $to)
    {
        return $this->db->table($this->table)
            ->where('start_date', '<', $to)
            ->where('end_date', '>', $from)
            ->order_by('start_date', 'ASC')
            ->get_all();
    }

    public function hasOverlap($roomId, $from, $to)
    {
        $count = $this->db->table($this->table)
            ->where('room_id', (int) $roomId)
            ->where('start_date', '<', $to)
            ->where('end_date', '>', $from)
            ->count();

        return (int) $count > 0;
    }
}