<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Dashboard extends MY_Controller {
	public $datah = array();
	public $data = array();

	public function __construct() {
        parent::__construct();
        // load model
        $this->load->model('hak_model');
        $this->load->model('web_model');
        $this->load->model('log_model');
        $this->load->model('buku_model');
        $this->load->model('berkas_model');

        // data header
		$this->datah['menu'] = $this->user_model->get_menu($this->user_model->get_roleid());
		$this->datah['title'] = ucfirst( $this->router->method == 'index' ? $this->router->class : $this->router->method );
		$this->datah['aktif'] = array(
			'parent' => '#parent-' . ( $this->router->method == 'index' ? $this->router->class : $this->router->method ),
			'child' => '');
		$this->datah['menudesk'] = $this->hak_model->select(array('akses_nama' => strtolower($this->datah['title']) ), 1);
		$this->datah['daftarlog'] = $this->log_model->select(array(), 6);
		$this->datah['boxlognotif'] = $this->log_model->get_total(array('log_status' => 0));

		// data content
		$this->data['web'] = $this->web_model->select();
    }

	public function index() {
		$this->data['daftarlog'] = $this->log_model->select(array(), 4);
		$this->data['daftarkoran'] = $this->berkas_model->select(array(), 4);
		$this->data['daftarbuku'] = $this->buku_model->select(array(), 4);

		$this->load->view('Backend/header_view', $this->datah);
		$this->load->view('Backend/dashboard_view', $this->data);
	}

	public function log() {
		$this->load->view('Backend/header_view', $this->datah);
		$this->load->view('Backend/log_view', $this->data);
	}

	public function notifikasi() {
		$this->load->view('Backend/header_view', $this->datah);
		$this->load->view('Backend/dashboard_view', $this->data);
	}

	public function buku() {
		// load model
		$this->load->model('buku_model');
		
		$this->data['listjenis'] = $this->buku_model->listjenis(array('*'))->result();
		$this->data['listkoleksi'] = $this->buku_model->listkoleksi(array('*'))->result();

		$this->load->view('Backend/header_view', $this->datah);
		$this->load->view('Backend/buku_view', $this->data);
	}

	public function koran() {
		$this->load->view('Backend/header_view', $this->datah);
		$this->load->view('Backend/koran_view', $this->data);
	}

	public function berita() {
		$this->load->view('Backend/header_view', $this->datah);
		$this->load->view('Backend/dashboard_view', $this->data);
	}

	public function frontend() {
		$this->load->view('Backend/header_view', $this->datah);
		$this->load->view('Backend/dashboard_view', $this->data);
	}

	public function manajemen($param) {
		$this->datah['aktif']['child'] = '#child-' . $param;
		$this->datah['title'] = ucfirst( $param );
		$this->datah['menudesk'] = $this->hak_model->select(array('akses_nama' => strtolower($param) ), 1);

		$this->load->view('Backend/header_view', $this->datah);
		$this->load->view('Backend/dashboard_view', $this->data);
	}

	public function logout() {
		$this->user_model->logout();
		$this->session->set_userdata('perpus_pesan_sukses', "Session Anda berhasil diakhiri");
		redirect('login');
	}

	public function get_databox() {
        // data buat box
        $data['boxlog'] = $this->log_model->get_total();
        $data['boxbuku'] = $this->buku_model->get_totalbuku();
        $data['boxkoran'] = $this->berkas_model->get_total();
        $data['boxdownload'] = $this->berkas_model->get_totaldownload();

        echo json_encode($data);
    }

}
