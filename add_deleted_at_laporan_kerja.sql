-- Jalankan script ini sekali untuk mengaktifkan soft delete.
ALTER TABLE laporan_kerja
    ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL AFTER pekerjaan;

-- Index dasar untuk query list/filter.
CREATE INDEX IF NOT EXISTS idx_laporan_kerja_karyawan_tanggal
    ON laporan_kerja (karyawan_id, tanggal);

CREATE INDEX IF NOT EXISTS idx_laporan_kerja_deleted_at
    ON laporan_kerja (deleted_at);
