<?php include "layout/header.php"; ?>
<?php include "config/database.php"; ?>
<?php

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

function masa_kerja($tanggal)
{
    try {
        $masuk = new DateTime($tanggal);
        $hari_ini = new DateTime();
        $diff = $hari_ini->diff($masuk);
        return $diff->y . " Tahun " . $diff->m . " Bulan";
    } catch (Exception $e) {
        return "-";
    }
}

function upload_foto($file)
{
    if (!isset($file) || !isset($file["error"]) || $file["error"] !== UPLOAD_ERR_OK) {
        return "default.png";
    }

    $allowed_ext = ["jpg", "jpeg", "png", "gif", "webp"];
    $original_name = $file["name"] ?? "";
    $tmp_name = $file["tmp_name"] ?? "";
    $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));

    if (!in_array($ext, $allowed_ext, true) || !is_uploaded_file($tmp_name)) {
        return "default.png";
    }

    if (!is_dir("upload")) {
        mkdir("upload", 0755, true);
    }

    $new_name = "foto_" . uniqid("", true) . "." . $ext;
    $target = "upload/" . $new_name;

    if (!move_uploaded_file($tmp_name, $target)) {
        return "default.png";
    }

    return $new_name;
}

$pesan_sukses = "";
$pesan_error = "";

# TAMBAH
if (isset($_POST["simpan"])) {
    $nama = trim($_POST["nama"] ?? "");
    $jabatan = trim($_POST["jabatan"] ?? "");
    $departemen = trim($_POST["departemen"] ?? "");
    $gaji_pokok = (float) ($_POST["gaji_pokok"] ?? 0);
    $tanggal_masuk = $_POST["tanggal_masuk"] ?? "";

    $username = trim($_POST["username"] ?? "");
    $password_input = $_POST["password"] ?? "";
    $password = md5($password_input);

    $nama_bank = trim($_POST["nama_bank"] ?? "");
    $nomor_rekening = trim($_POST["nomor_rekening"] ?? "");
    $foto = upload_foto($_FILES["foto"] ?? null);

    if ($nama === "" || $jabatan === "" || $departemen === "" || $tanggal_masuk === "" || $username === "" || $password_input === "") {
        $pesan_error = "Data wajib belum lengkap.";
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO karyawan
            (nama,jabatan,departemen,gaji_pokok,tanggal_masuk,nama_bank,nomor_rekening,foto)
            VALUES (?,?,?,?,?,?,?,?)"
        );

        if ($stmt) {
            $stmt->bind_param(
                "sssdssss",
                $nama,
                $jabatan,
                $departemen,
                $gaji_pokok,
                $tanggal_masuk,
                $nama_bank,
                $nomor_rekening,
                $foto
            );

            if ($stmt->execute()) {
                $stmt_user = $conn->prepare("INSERT INTO users(nama,username,password,role) VALUES(?,?,?,'karyawan')");
                if ($stmt_user) {
                    $stmt_user->bind_param("sss", $nama, $username, $password);
                    if (!$stmt_user->execute()) {
                        $pesan_error = "Karyawan tersimpan, tapi user login gagal dibuat.";
                    } else {
                        $pesan_sukses = "Data karyawan berhasil ditambahkan.";
                    }
                    $stmt_user->close();
                } else {
                    $pesan_error = "Karyawan tersimpan, tapi user login gagal diproses.";
                }
            } else {
                $pesan_error = "Gagal menambahkan data karyawan.";
            }
            $stmt->close();
        } else {
            $pesan_error = "Query simpan tidak valid.";
        }
    }
}

# UPDATE
if (isset($_POST["update"])) {
    $id = (int) ($_POST["id"] ?? 0);
    $nama = trim($_POST["nama"] ?? "");
    $jabatan = trim($_POST["jabatan"] ?? "");
    $departemen = trim($_POST["departemen"] ?? "");
    $gaji_pokok = (float) ($_POST["gaji_pokok"] ?? 0);
    $tanggal_masuk = $_POST["tanggal_masuk"] ?? "";
    $nama_bank = trim($_POST["nama_bank"] ?? "");
    $nomor_rekening = trim($_POST["nomor_rekening"] ?? "");

    if ($id <= 0 || $nama === "" || $jabatan === "" || $departemen === "" || $tanggal_masuk === "") {
        $pesan_error = "Data update tidak valid.";
    } else {
        $foto_baru = upload_foto($_FILES["foto"] ?? null);
        if ($foto_baru !== "default.png") {
            $stmt = $conn->prepare(
                "UPDATE karyawan
                SET nama=?, jabatan=?, departemen=?, gaji_pokok=?, tanggal_masuk=?, nama_bank=?, nomor_rekening=?, foto=?
                WHERE id=?"
            );
            if ($stmt) {
                $stmt->bind_param(
                    "sssdssssi",
                    $nama,
                    $jabatan,
                    $departemen,
                    $gaji_pokok,
                    $tanggal_masuk,
                    $nama_bank,
                    $nomor_rekening,
                    $foto_baru,
                    $id
                );
                $pesan_sukses = $stmt->execute() ? "Data karyawan berhasil diperbarui." : "";
                if ($pesan_sukses === "") {
                    $pesan_error = "Gagal memperbarui data karyawan.";
                }
                $stmt->close();
            } else {
                $pesan_error = "Query update tidak valid.";
            }
        } else {
            $stmt = $conn->prepare(
                "UPDATE karyawan
                SET nama=?, jabatan=?, departemen=?, gaji_pokok=?, tanggal_masuk=?, nama_bank=?, nomor_rekening=?
                WHERE id=?"
            );
            if ($stmt) {
                $stmt->bind_param(
                    "sssdsssi",
                    $nama,
                    $jabatan,
                    $departemen,
                    $gaji_pokok,
                    $tanggal_masuk,
                    $nama_bank,
                    $nomor_rekening,
                    $id
                );
                $pesan_sukses = $stmt->execute() ? "Data karyawan berhasil diperbarui." : "";
                if ($pesan_sukses === "") {
                    $pesan_error = "Gagal memperbarui data karyawan.";
                }
                $stmt->close();
            } else {
                $pesan_error = "Query update tidak valid.";
            }
        }
    }
}

# RESET PASSWORD
if (isset($_GET["reset"])) {
    $nama = trim($_GET["reset"]);
    $password = md5("123456");

    $stmt = $conn->prepare("UPDATE users SET password=? WHERE nama=?");
    if ($stmt) {
        $stmt->bind_param("ss", $password, $nama);
        if ($stmt->execute()) {
            $pesan_sukses = "Password berhasil direset menjadi 123456.";
        } else {
            $pesan_error = "Reset password gagal.";
        }
        $stmt->close();
    } else {
        $pesan_error = "Query reset password tidak valid.";
    }
}

# HAPUS
if (isset($_GET["hapus"])) {
    $id = (int) $_GET["hapus"];
    if ($id > 0) {
        $nama_hapus = "";
        $foto_hapus = "";

        $stmt_get = $conn->prepare("SELECT nama, foto FROM karyawan WHERE id=?");
        if ($stmt_get) {
            $stmt_get->bind_param("i", $id);
            $stmt_get->execute();
            $res_get = $stmt_get->get_result();
            if ($res_get && $res_get->num_rows > 0) {
                $row_get = $res_get->fetch_assoc();
                $nama_hapus = $row_get["nama"] ?? "";
                $foto_hapus = $row_get["foto"] ?? "";
            }
            $stmt_get->close();
        }

        $stmt_del = $conn->prepare("DELETE FROM karyawan WHERE id=?");
        if ($stmt_del) {
            $stmt_del->bind_param("i", $id);
            if ($stmt_del->execute()) {
                if ($nama_hapus !== "") {
                    $stmt_user = $conn->prepare("DELETE FROM users WHERE nama=?");
                    if ($stmt_user) {
                        $stmt_user->bind_param("s", $nama_hapus);
                        $stmt_user->execute();
                        $stmt_user->close();
                    }
                }

                if ($foto_hapus !== "" && $foto_hapus !== "default.png") {
                    $path = "upload/" . $foto_hapus;
                    if (file_exists($path)) {
                        @unlink($path);
                    }
                }

                $pesan_sukses = "Data karyawan berhasil dihapus.";
            } else {
                $pesan_error = "Gagal menghapus data karyawan.";
            }
            $stmt_del->close();
        } else {
            $pesan_error = "Query hapus tidak valid.";
        }
    } else {
        $pesan_error = "ID karyawan tidak valid.";
    }
}

$data = mysqli_query($conn, "SELECT * FROM karyawan ORDER BY id DESC");

?>

<div class="card">

<div class="card-header">

<h3 class="card-title">Manajemen Karyawan</h3>

<div class="card-tools">

<button class="btn btn-primary" data-toggle="modal" data-target="#tambah">
<i class="fas fa-user-plus"></i> Tambah Karyawan
</button>

</div>

</div>

<div class="card-body">

<?php if ($pesan_sukses !== "") { ?>
    <div class="alert alert-success"><?php echo e($pesan_sukses); ?></div>
<?php } ?>

<?php if ($pesan_error !== "") { ?>
    <div class="alert alert-danger"><?php echo e($pesan_error); ?></div>
<?php } ?>

<table class="table table-bordered table-hover" id="tabel">

<thead>

<tr>
<th>No</th>
<th>Foto</th>
<th>Nama</th>
<th>Jabatan</th>
<th>Departemen</th>
<th>Gaji Pokok</th>
<th>Tanggal Masuk</th>
<th>Masa Kerja</th>
<th>Bank</th>
<th>No Rekening</th>
<th width="150">Aksi</th>
</tr>

</thead>

<tbody>

<?php
$no = 1;
while ($d = mysqli_fetch_assoc($data)) {
    $foto = $d["foto"] ? $d["foto"] : "default.png";
?>

<tr>

<td data-label="No"><?php echo $no++; ?></td>

<td data-label="Foto">
<img src="upload/<?php echo e($foto); ?>"
width="40"
height="40"
style="border-radius:50%;object-fit:cover">
</td>

<td data-label="Nama"><?php echo e($d["nama"]); ?></td>

<td data-label="Jabatan">
<span class="badge badge-success">
<?php echo e($d["jabatan"]); ?>
</span>
</td>

<td data-label="Departemen">
<span class="badge badge-info">
<?php echo e($d["departemen"]); ?>
</span>
</td>

<td data-label="Gaji Pokok">
Rp <?php echo number_format((float) $d["gaji_pokok"]); ?>
</td>

<td data-label="Tanggal Masuk">
<?php echo e($d["tanggal_masuk"]); ?>
</td>

<td data-label="Masa Kerja">
<?php echo e(masa_kerja($d["tanggal_masuk"])); ?>
</td>

<td data-label="Bank">
<?php echo e($d["nama_bank"]); ?>
</td>

<td data-label="No Rekening">
<?php echo e($d["nomor_rekening"]); ?>
</td>

<td data-label="Aksi">

<button class="btn btn-warning btn-sm"
data-toggle="modal"
data-target="#edit<?php echo (int) $d["id"]; ?>">
<i class="fas fa-edit"></i>
</button>

<a href="?reset=<?php echo urlencode($d["nama"]); ?>"
class="btn btn-info btn-sm"
onclick="return confirm('Reset password karyawan ini?')">
<i class="fas fa-key"></i>
</a>

<a href="?hapus=<?php echo (int) $d["id"]; ?>"
class="btn btn-danger btn-sm"
onclick="return confirm('Hapus karyawan ini?')">
<i class="fas fa-trash"></i>
</a>

</td>

</tr>

<?php } ?>

</tbody>

</table>

</div>

</div>

<style>

@media (max-width:768px){

table thead{
display:none;
}

table, table tbody, table tr, table td{
display:block;
width:100%;
}

table tr{
margin-bottom:15px;
border:1px solid #ddd;
border-radius:10px;
padding:10px;
background:#fff;
}

table td{
text-align:right;
padding-left:50%;
position:relative;
}

table td::before{
content:attr(data-label);
position:absolute;
left:10px;
width:45%;
font-weight:bold;
text-align:left;
}

}

</style>

<script>

$(document).ready(function(){

$('#tabel').DataTable();

});

</script>

<!-- MODAL TAMBAH KARYAWAN -->
<div class="modal fade" id="tambah">
<div class="modal-dialog">
<div class="modal-content">

<form method="POST" enctype="multipart/form-data">

<div class="modal-header">
<h4 class="modal-title">Tambah Karyawan</h4>
<button type="button" class="close" data-dismiss="modal">&times;</button>
</div>

<div class="modal-body">

<div class="form-group">
<label>Nama</label>
<input type="text" name="nama" class="form-control" required>
</div>

<div class="form-group">
<label>Jabatan</label>
<input type="text" name="jabatan" class="form-control" required>
</div>

<div class="form-group">
<label>Departemen</label>
<input type="text" name="departemen" class="form-control" required>
</div>

<div class="form-group">
<label>Gaji Pokok</label>
<input type="number" name="gaji_pokok" class="form-control" required>
</div>

<div class="form-group">
<label>Nama Bank</label>
<input type="text" name="nama_bank" class="form-control">
</div>

<div class="form-group">
<label>Nomor Rekening</label>
<input type="text" name="nomor_rekening" class="form-control">
</div>

<div class="form-group">
<label>Tanggal Masuk</label>
<input type="date" name="tanggal_masuk" class="form-control" required>
</div>

<hr>

<div class="form-group">
<label>Username Login</label>
<input type="text" name="username" class="form-control" required>
</div>

<div class="form-group">
<label>Password</label>
<input type="password" name="password" class="form-control" required>
</div>

<div class="form-group">
<label>Foto</label>
<input type="file" name="foto" class="form-control" accept=".jpg,.jpeg,.png,.gif,.webp">
</div>

</div>

<div class="modal-footer">

<button type="submit" name="simpan" class="btn btn-primary">
<i class="fas fa-save"></i> Simpan
</button>

<button type="button" class="btn btn-secondary" data-dismiss="modal">
Batal
</button>

</div>

</form>

</div>
</div>
</div>


<?php
$data_edit = mysqli_query($conn, "SELECT * FROM karyawan ORDER BY id DESC");

while ($e = mysqli_fetch_assoc($data_edit)) {
?>

<div class="modal fade" id="edit<?php echo (int) $e["id"]; ?>">

<div class="modal-dialog">

<div class="modal-content">

<form method="POST" enctype="multipart/form-data">

<div class="modal-header">
<h4 class="modal-title">Edit Karyawan</h4>
<button type="button" class="close" data-dismiss="modal">&times;</button>
</div>

<div class="modal-body">

<input type="hidden" name="id" value="<?php echo (int) $e["id"]; ?>">

<div class="form-group">
<label>Nama</label>
<input type="text" name="nama" class="form-control"
value="<?php echo e($e["nama"]); ?>" required>
</div>

<div class="form-group">
<label>Jabatan</label>
<input type="text" name="jabatan" class="form-control"
value="<?php echo e($e["jabatan"]); ?>" required>
</div>

<div class="form-group">
<label>Departemen</label>
<input type="text" name="departemen" class="form-control"
value="<?php echo e($e["departemen"]); ?>" required>
</div>

<div class="form-group">
<label>Gaji Pokok</label>
<input type="number" name="gaji_pokok" class="form-control"
value="<?php echo (float) $e["gaji_pokok"]; ?>" required>
</div>

<div class="form-group">
<label>Tanggal Masuk</label>
<input type="date" name="tanggal_masuk" class="form-control"
value="<?php echo e($e["tanggal_masuk"]); ?>" required>
</div>

<div class="form-group">
<label>Nama Bank</label>
<input type="text" name="nama_bank" class="form-control"
value="<?php echo e($e["nama_bank"]); ?>">
</div>

<div class="form-group">
<label>Nomor Rekening</label>
<input type="text" name="nomor_rekening" class="form-control"
value="<?php echo e($e["nomor_rekening"]); ?>">
</div>

<div class="form-group">
<label>Foto</label>
<input type="file" name="foto" class="form-control" accept=".jpg,.jpeg,.png,.gif,.webp">
</div>

</div>

<div class="modal-footer">

<button type="submit" name="update" class="btn btn-success">
Simpan
</button>

<button type="button" class="btn btn-secondary" data-dismiss="modal">
Batal
</button>

</div>

</form>

</div>

</div>

</div>

<?php } ?>
<?php include "layout/footer.php"; ?>
