<?php
/**
 * Plugin Name: Jetpack WebP CDN Replacer
 * Description: Tự động thay thế hình ảnh tải lên bằng phiên bản WebP từ CDN của Jetpack và cập nhật meta dữ liệu.
 * Version: 1.2
 * Author: bibica
 * Author URI: https://bibica.net
 * Text Domain: jetpack-webp-cdn-replacer
 * License: GPL-3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.txt
 */
class Jetpack_WebP_CDN_Replacer {

    private $batch_size = 20; // Số lượng ảnh xử lý mỗi lần
  #  private $cron_interval = 5; // Khoảng cách thời gian giữa các lần xử lý batch (giây)

    public function __construct() {
        // Hook để lên lịch xử lý ảnh sau khi ảnh được upload
        add_action('add_attachment', array($this, 'schedule_image_processing'));

        // Hook cron job để xử lý ảnh
        add_action('jetpack_webp_cdn_replacer_process', array($this, 'process_images_batch'));

        // Xóa transient khi plugin được kích hoạt để đảm bảo trạng thái sạch sẽ
        register_activation_hook(__FILE__, array($this, 'clear_transients'));

        // Xóa transient khi plugin được hủy kích hoạt để đảm bảo trạng thái sạch sẽ
        register_deactivation_hook(__FILE__, array($this, 'clear_transients'));
    }

    // Xóa tất cả transient liên quan đến plugin
    public function clear_transients() {
        delete_transient('jetpack_webp_cdn_replacer_processing');
    }

    // Tạo thư mục log nếu chưa tồn tại
    private function create_log_directory() {
        $log_dir = WP_CONTENT_DIR . '/jetpack-webp-cdn-replacer';
        if (!file_exists($log_dir)) {
            mkdir($log_dir, 0755, true);
        }
    }

    // Đọc đường dẫn file log
    private function get_log_file_path() {
        $this->create_log_directory(); // Tạo thư mục log nếu chưa tồn tại
        return WP_CONTENT_DIR . '/jetpack-webp-cdn-replacer/jetpack-webp-cdn-replacer.log';
    }

    // Hàm lên lịch xử lý ảnh
    public function schedule_image_processing($attachment_id) {
        $pending_images = get_option('jetpack_webp_cdn_replacer_pending_images', array());
        if (!in_array($attachment_id, $pending_images)) {
            $pending_images[] = $attachment_id;
            update_option('jetpack_webp_cdn_replacer_pending_images', $pending_images);

            // Đặt transient để theo dõi trạng thái
            if (false === get_transient('jetpack_webp_cdn_replacer_processing')) {
                set_transient('jetpack_webp_cdn_replacer_processing', true, 15 * MINUTE_IN_SECONDS);
                // Đặt cron job để xử lý ảnh ngay lập tức
                wp_schedule_single_event(time(), 'jetpack_webp_cdn_replacer_process');
            }
        }
    }

    // Hàm xử lý ảnh theo từng batch
    public function process_images_batch() {
        $processing_transient = get_transient('jetpack_webp_cdn_replacer_processing');
        if (!$processing_transient) {
            return; // Nếu transient không tồn tại, không làm gì cả
        }

        $pending_images = get_option('jetpack_webp_cdn_replacer_pending_images', array());

        if (empty($pending_images)) {
            // Xóa transient khi không còn ảnh trong hàng đợi và đảm bảo có một cron job mới để kiểm tra tình trạng tiếp theo
            delete_transient('jetpack_webp_cdn_replacer_processing');
            return;
        }

        $this->create_log_directory(); // Đảm bảo thư mục log tồn tại

        $batch = array_splice($pending_images, 0, $this->batch_size);
        update_option('jetpack_webp_cdn_replacer_pending_images', $pending_images);

        foreach ($batch as $attachment_id) {
            $this->process_image($attachment_id);
        }

        // Lên lịch xử lý batch ảnh tiếp theo ngay lập tức nếu còn ảnh trong hàng đợi
        if (!empty($pending_images)) {
            // Đặt transient để theo dõi trạng thái
            set_transient('jetpack_webp_cdn_replacer_processing', true, 15 * MINUTE_IN_SECONDS);
            wp_schedule_single_event(time(), 'jetpack_webp_cdn_replacer_process');
        } else {
            // Không xóa transient ngay lập tức nếu không còn ảnh, để đảm bảo rằng cron job sẽ không bị bỏ lỡ
            delete_transient('jetpack_webp_cdn_replacer_processing');
        }
    }

    // Hàm xử lý ảnh
    public function process_image($attachment_id) {
        $attachment = get_post($attachment_id);
        if ($attachment && strpos($attachment->post_mime_type, 'image/') !== false) {
            $image_url = wp_get_attachment_url($attachment_id);
            $relative_path = parse_url($image_url, PHP_URL_PATH);
            $upload_dir = wp_upload_dir();
            $relative_path_for_file = str_replace('/wp-content/uploads/', '', $relative_path);
            $file_path = $upload_dir['basedir'] . '/' . $relative_path_for_file;
            $original_domain = parse_url($image_url, PHP_URL_HOST);
            $cdn_url = 'https://i0.wp.com/' . $original_domain . $relative_path . '?format=webp';

            if (!is_writable($upload_dir['basedir'])) {
                $this->log_error("Thư mục không có quyền ghi: " . $upload_dir['basedir']);
                return; // Thư mục không có quyền ghi
            }

            $response = wp_remote_get($cdn_url, array('headers' => array('Accept' => 'image/webp')));

            if (is_wp_error($response)) {
                $this->log_error("Không thể tải ảnh từ CDN: $cdn_url");
                return; // Không thể tải ảnh từ CDN
            }

            $status_code = wp_remote_retrieve_response_code($response);
            if ($status_code !== 200) {
                $this->log_error("Lỗi HTTP $status_code khi tải ảnh từ CDN: $cdn_url");
                return; // Lỗi HTTP khi tải ảnh từ CDN
            }

            $cdn_image = wp_remote_retrieve_body($response);

            if (empty($cdn_image)) {
                $this->log_error("Dữ liệu ảnh WebP nhận được từ CDN rỗng: $cdn_url");
                return; // Nội dung ảnh WebP rỗng
            }

            if (file_put_contents($file_path, $cdn_image) === false) {
                $this->log_error("Không thể ghi ảnh vào file: $file_path");
                return; // Không thể ghi ảnh vào file
            }

            // Tải file chứa các hàm cần thiết nếu chưa được tải
            if (!function_exists('wp_generate_attachment_metadata')) {
                require_once ABSPATH . 'wp-admin/includes/image.php';
            }

            // Cập nhật meta dữ liệu bằng wp_generate_attachment_metadata
            $meta_data = wp_generate_attachment_metadata($attachment_id, $file_path);
            wp_update_attachment_metadata($attachment_id, $meta_data);

            // Cập nhật file meta
            update_post_meta($attachment_id, '_wp_attached_file', $relative_path_for_file);

            $this->log_success("Xử lý ảnh thành công: $attachment_id");
        }
    }

    // Ghi log thông tin thành công
    private function log_success($message) {
        $log_file = $this->get_log_file_path();
        $log_message = date('Y-m-d H:i:s') . " SUCCESS: $message\n";
        file_put_contents($log_file, $log_message, FILE_APPEND);
    }

    // Ghi log lỗi
    private function log_error($message) {
        $log_file = $this->get_log_file_path();
        $log_message = date('Y-m-d H:i:s') . " ERROR: $message\n";
        file_put_contents($log_file, $log_message, FILE_APPEND);
    }
}

// Khởi tạo plugin
new Jetpack_WebP_CDN_Replacer();