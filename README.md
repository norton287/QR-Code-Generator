# QR Code Generator
- This QR Code generator is a web-based tool that allows users to create custom QR codes. It offers a variety of customization options, including the ability to change the colors of different QR code elements, adjust the mask pattern, border size, image format, and image size.

## Features
- Customizable Colors: Change the colors of the QR code's finder, alignment patterns, data modules, and quiet zone.
- Mask Pattern Selection: Choose from different mask patterns to optimize the QR code's appearance and readability.
- Border Size Adjustment: Control the size of the quiet zone (border) around the QR code.
- Image Format Options: Generate QR codes in PNG, JPG, or GIF formats.
- Image Size Scaling: Adjust the size of the generated QR code image.
## Technologies Used
- PHP: The server-side language used to process user input and generate QR codes.
- Chillerlan/PHP-QRCode library: A PHP library for creating QR codes.
- HTML, CSS, JavaScript: The front-end technologies used to create the user interface and interact with the server.
- Tailwind CSS: A utility-first CSS framework for styling the interface.
## How to Use
- Input Text: Enter the text or data you want to encode in the QR code.
## Customize (Optional):
- Select a mask pattern.
- Choose the quiet zone size.
- Select the desired output image format (PNG, JPG, or GIF).
- Adjust the scale (size) of the QR code image.
- Customize the colors of different QR code elements.
- Generate QR Code: Click the "Generate QR Code" button.
- Download: Once the QR code is generated, click the "Download QR Code" button to save it to your device.
## Installation
### Clone the Repository:
```Bash
git clone https://github.com/norton287/QR-Code-Generator.git
```
### Install Dependencies:
```Bash
composer require chillerlan/php-qrcode
```
- Set Up Web Server: Configure a web server (e.g., Apache, Nginx) to serve the project files.
- Access the Generator: Open the generator in your web browser by navigating to the URL where you've set it up.
### Contributing
- Contributions are welcome! If you find any bugs or have suggestions for improvements, please open an issue or submit a pull request.

### License
- This project is licensed under the MIT License.
